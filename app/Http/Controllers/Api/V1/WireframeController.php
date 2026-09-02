<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\IdempotencyInProgressException;
use App\Exceptions\IdempotencyStoreUnavailableException;
use App\Exceptions\InvalidWireframeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateWireframeRequest;
use App\Http\Resources\WireframeResource;
use App\Services\IdempotentWireframeGenerator;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class WireframeController extends Controller
{
    public function store(
        GenerateWireframeRequest $request,
        IdempotentWireframeGenerator $generator,
    ): JsonResponse {
        if ($request->validated('generation_mode') === 'section_parallel') {
            return Problem::response($request, 422, 'generation_mode_requires_async', 'Section-parallel generation is available through the wireframe jobs endpoint.');
        }
        try {
            $result = $generator->generate(
                $request->idempotencyKey(),
                $request->getContent(),
                (string) $request->validated('contract_version'),
                $request->validated('site_ast'),
                $request->validated('brief'),
                $request->validated('locale'),
                (int) $request->validated('wireframe_ast_version'),
                (string) $request->validated('execution_profile'),
            );
        } catch (IdempotencyConflictException $exception) {
            return Problem::response($request, 409, 'idempotency_conflict', $exception->getMessage());
        } catch (IdempotencyInProgressException $exception) {
            return Problem::response($request, 409, 'idempotency_in_progress', $exception->getMessage());
        } catch (IdempotencyStoreUnavailableException $exception) {
            return Problem::response($request, 503, 'idempotency_store_unavailable', $exception->getMessage());
        } catch (InvalidWireframeException $exception) {
            return Problem::response($request, 422, 'invalid_wireframe', $exception->getMessage());
        } catch (RuntimeException $exception) {
            return Problem::response($request, 502, 'wireframe_provider_failed', $exception->getMessage());
        }

        $resource = new WireframeResource($result['wireframe']);
        if ($result['telemetry'] !== null) {
            $resource->additional(['telemetry' => $result['telemetry']]);
        }

        return $resource->response()->withHeaders([
            'Idempotent-Replayed' => $result['replayed'] ? 'true' : 'false',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
