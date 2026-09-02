<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidWireframeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateWireframeRequest;
use App\Services\OpenAiWireframeGenerator;
use App\Support\Problem;
use App\Support\WireframeJobStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class WireframeJobController extends Controller
{
    public function store(
        GenerateWireframeRequest $request,
        WireframeJobStore $jobs,
        OpenAiWireframeGenerator $generator,
    ): JsonResponse {
        $payload = $request->validated();
        $claim = $jobs->claim($request->idempotencyKey(), $payload);
        if ($claim['conflict']) {
            return Problem::response($request, 409, 'idempotency_conflict', 'The Idempotency-Key was already used with another request.');
        }
        if ($claim['created']) {
            try {
                $providerResponse = $generator->startBackground(
                    $payload['site_ast'],
                    $payload['brief'],
                    (string) $payload['locale'],
                    (int) $payload['wireframe_ast_version'],
                    executionProfile: (string) $payload['execution_profile'],
                );
                $claim['job'] = $jobs->started(
                    (string) $claim['job']['id'],
                    (string) $providerResponse['id'],
                    (string) $providerResponse['status'],
                );
            } catch (RuntimeException $exception) {
                $jobs->failed((string) $claim['job']['id'], 'wireframe_provider_failed', 'The background wireframe generation could not be started.');

                return Problem::response($request, 502, 'wireframe_provider_failed', $exception->getMessage());
            }
        }

        return response()->json($claim['job'], 202, ['Cache-Control' => 'private, no-store']);
    }

    public function show(
        Request $request,
        string $job,
        WireframeJobStore $jobs,
        OpenAiWireframeGenerator $generator,
    ): JsonResponse {
        $record = $jobs->find($job);
        if ($record === null) {
            return Problem::response($request, 404, 'job_not_found', 'The wireframe job does not exist or has expired.');
        }
        if (! in_array($record['status'] ?? null, ['queued', 'in_progress'], true)) {
            return response()->json($record, headers: ['Cache-Control' => 'private, no-store']);
        }
        $context = $jobs->providerContext($job);
        if ($context === null) {
            return Problem::response($request, 503, 'job_store_unavailable', 'The wireframe job context is unavailable.');
        }

        $completionClaimed = false;
        try {
            $providerResponse = $generator->retrieveBackground($context['provider_response_id']);
            $providerStatus = (string) $providerResponse['status'];
            if (in_array($providerStatus, ['queued', 'in_progress'], true)) {
                $record = $jobs->pending($job, $providerStatus);
            } elseif ($providerStatus === 'completed') {
                if (! $jobs->claimProviderCompletion($job, $context['provider_response_id'], $context['semantic_attempt'])) {
                    return response()->json($jobs->find($job), headers: ['Cache-Control' => 'private, no-store']);
                }
                $completionClaimed = true;
                $payload = $context['payload'];
                $completed = $generator->completeBackground(
                    $providerResponse,
                    $payload['site_ast'],
                    (string) $payload['locale'],
                    (int) $payload['wireframe_ast_version'],
                );
                $record = $jobs->succeeded($job, [
                    'wireframe_ast' => $completed['wireframe'],
                    'telemetry' => $completed['telemetry'],
                ]);
            } else {
                $record = $jobs->failed($job, 'wireframe_generation_failed', 'The background wireframe generation reached a terminal provider state without a valid result.');
            }
        } catch (InvalidWireframeException $exception) {
            if ($context['semantic_attempt'] < 2) {
                if (! $jobs->claimSemanticRetry($job, $context['provider_response_id'], $context['semantic_attempt'])) {
                    return response()->json($jobs->find($job), headers: ['Cache-Control' => 'private, no-store']);
                }
                $jobs->recordAttemptTelemetry($job, $generator->backgroundTelemetry($providerResponse));
                try {
                    $payload = $context['payload'];
                    $providerResponse = $generator->startBackground(
                        $payload['site_ast'],
                        $payload['brief'],
                        (string) $payload['locale'],
                        (int) $payload['wireframe_ast_version'],
                        $exception->getMessage(),
                        (string) $payload['execution_profile'],
                    );
                    $record = $jobs->started(
                        $job,
                        (string) $providerResponse['id'],
                        (string) $providerResponse['status'],
                    );
                } catch (RuntimeException) {
                    $record = $jobs->failed($job, 'wireframe_provider_failed', 'The background wireframe repair could not be started.');
                }
            } else {
                $jobs->recordAttemptTelemetry($job, $generator->backgroundTelemetry($providerResponse));
                $record = $jobs->failed($job, 'invalid_wireframe', 'The generated Wireframe AST did not satisfy the deterministic contract after two semantic attempts.');
            }
        } catch (RuntimeException $exception) {
            if ($completionClaimed) {
                $record = $jobs->failed($job, 'invalid_provider_response', 'The completed wireframe provider response could not be processed.');

                return response()->json($record, headers: ['Cache-Control' => 'private, no-store']);
            }

            return Problem::response($request, 502, 'wireframe_provider_failed', $exception->getMessage());
        }

        return response()->json($record, headers: ['Cache-Control' => 'private, no-store']);
    }
}
