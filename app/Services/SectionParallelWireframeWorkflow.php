<?php

namespace App\Services;

use App\Exceptions\InvalidWireframeException;
use App\Support\WireframeJobStore;
use RuntimeException;
use Throwable;

final class SectionParallelWireframeWorkflow
{
    public function __construct(private readonly OpenAiWireframeGenerator $generator) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function start(string $jobId, array $payload, WireframeJobStore $jobs): array
    {
        $response = $this->generator->startPlanBackground(
            $payload['site_ast'],
            $payload['brief'],
            (string) $payload['locale'],
            (string) $payload['execution_profile'],
        );

        return $jobs->workflowStarted($jobId, $response);
    }

    /** @return array<string,mixed> */
    public function advance(string $jobId, WireframeJobStore $jobs): array
    {
        $context = $jobs->workflowContext($jobId);
        if ($context === null) {
            throw new RuntimeException('The wireframe workflow context is unavailable.');
        }
        $revision = $context['revision'];
        if (! $jobs->claimWorkflowAdvance($jobId, $revision)) {
            return $jobs->find($jobId) ?? throw new RuntimeException('The wireframe workflow does not exist.');
        }
        try {
            $transition = match ($context['workflow']['stage'] ?? null) {
                'planning' => $this->advancePlanning($context['payload'], $context['workflow']),
                'sections' => $this->advanceSections($context['payload'], $context['workflow']),
                'sections_draining' => $this->advanceSectionDrain($context['workflow']),
                'review' => $this->advanceReview($context['payload'], $context['workflow']),
                default => throw new RuntimeException('The wireframe workflow stage is invalid.'),
            };

            return $jobs->applyWorkflowTransition($jobId, $revision, $transition);
        } catch (Throwable $exception) {
            $jobs->releaseWorkflowAdvance($jobId, $revision);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $workflow @return array<string,mixed> */
    private function advancePlanning(array $payload, array $workflow): array
    {
        $response = $this->generator->retrieveBackground((string) $workflow['plan_response_id']);
        if ($this->pending($response)) {
            return $this->pendingTransition($workflow, 'planning', 0.1);
        }
        if (($response['status'] ?? null) !== 'completed') {
            return $this->failureTransition($workflow, $response, 'wireframe_plan_failed', 'The bounded wireframe plan could not be generated.');
        }
        try {
            $plan = $this->generator->completePlanBackground($response, $payload['site_ast'], $payload['brief']);
        } catch (InvalidWireframeException $exception) {
            return $this->failureTransition($workflow, $response, 'invalid_wireframe_plan', $exception->getMessage());
        }
        $tasks = [];
        foreach ($plan['pages'] as $pagePlan) {
            foreach ($pagePlan['sections'] as $sectionPlan) {
                $key = $pagePlan['key'].'::'.$sectionPlan['id'];
                $tasks[$key] = [
                    'page_plan' => $pagePlan,
                    'section_plan' => $sectionPlan,
                    'response_id' => null,
                    'provider_status' => 'unstarted',
                ];
            }
        }
        $workflow['stage'] = 'sections';
        $workflow['plan'] = $plan;
        $workflow['section_tasks'] = $tasks;

        return [
            'workflow' => $workflow,
            'changes' => ['status' => 'in_progress', 'stage' => 'sections', 'progress' => 0.15],
            'telemetry' => [$this->generator->backgroundTelemetry($response)],
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $workflow @return array<string,mixed> */
    private function advanceSections(array $payload, array $workflow): array
    {
        $tasks = $workflow['section_tasks'];
        $results = is_array($workflow['section_results'] ?? null) ? $workflow['section_results'] : [];
        $telemetry = [];
        $started = 0;
        $batchSize = max(1, min(8, (int) config('wireframe.jobs.section_start_batch', 6)));
        foreach ($tasks as $key => $task) {
            if (($task['response_id'] ?? null) !== null || $started >= $batchSize) {
                continue;
            }
            $response = $this->generator->startSectionBackground(
                $payload['site_ast'],
                $payload['brief'],
                (string) $payload['locale'],
                $workflow['plan'],
                $task['page_plan'],
                $task['section_plan'],
                (string) $payload['execution_profile'],
            );
            $tasks[$key]['response_id'] = $response['id'];
            $tasks[$key]['provider_status'] = $response['status'];
            $started++;
        }

        foreach ($tasks as $key => $task) {
            if (! is_string($task['response_id'] ?? null) || isset($results[$key])) {
                continue;
            }
            if (($workflow['section_tasks'][$key]['response_id'] ?? null) === null) {
                continue;
            }
            $response = $this->generator->retrieveBackground($task['response_id']);
            $tasks[$key]['provider_status'] = $response['status'] ?? 'unknown';
            if ($this->pending($response)) {
                continue;
            }
            if (($response['status'] ?? null) !== 'completed') {
                $workflow['section_tasks'] = $tasks;

                return $this->beginSectionDrain(
                    $workflow,
                    $key,
                    $response,
                    'wireframe_section_failed',
                    'A bounded wireframe section could not be generated.',
                );
            }
            try {
                $results[$key] = $this->generator->completeSectionBackground(
                    $response,
                    $task['page_plan'],
                    $task['section_plan'],
                );
            } catch (InvalidWireframeException $exception) {
                $workflow['section_tasks'] = $tasks;

                return $this->beginSectionDrain($workflow, $key, $response, 'invalid_wireframe_section', $exception->getMessage());
            }
            $tasks[$key]['telemetry_recorded'] = true;
            $telemetry[] = $this->generator->backgroundTelemetry($response);
        }
        $workflow['section_tasks'] = $tasks;
        $workflow['section_results'] = $results;
        $total = count($tasks);
        $completed = count($results);
        if ($completed < $total) {
            return [
                'workflow' => $workflow,
                'changes' => [
                    'status' => 'in_progress',
                    'stage' => 'sections',
                    'progress' => 0.15 + (0.65 * ($total === 0 ? 0 : $completed / $total)),
                ],
                'telemetry' => $telemetry,
            ];
        }
        try {
            $assembled = $this->generator->assembleSections(
                $workflow['plan'],
                $results,
                $payload['site_ast'],
                $payload['brief'],
                (string) $payload['locale'],
            );
            $reviewResponse = $this->generator->startReviewBackground(
                $payload['site_ast'],
                $payload['brief'],
                (string) $payload['locale'],
                $assembled,
                (string) $payload['execution_profile'],
            );
        } catch (InvalidWireframeException $exception) {
            return [
                'workflow' => $workflow,
                'changes' => [],
                'telemetry' => $telemetry,
                'failure' => ['type' => 'wireframe_assembly_failed', 'detail' => $exception->getMessage()],
            ];
        }
        $workflow['stage'] = 'review';
        $workflow['assembled'] = $assembled;
        $workflow['review_response_id'] = $reviewResponse['id'];

        return [
            'workflow' => $workflow,
            'changes' => ['status' => 'in_progress', 'stage' => 'review', 'progress' => 0.85],
            'telemetry' => $telemetry,
        ];
    }

    /** @param array<string,mixed> $workflow @return array<string,mixed> */
    private function advanceSectionDrain(array $workflow): array
    {
        $telemetry = [];
        $allTerminal = true;
        foreach ($workflow['section_tasks'] as $key => $task) {
            if (! is_string($task['response_id'] ?? null) || ($task['telemetry_recorded'] ?? false) === true) {
                continue;
            }
            $response = $this->generator->retrieveBackground($task['response_id']);
            $workflow['section_tasks'][$key]['provider_status'] = $response['status'] ?? 'unknown';
            if ($this->pending($response)) {
                $allTerminal = false;

                continue;
            }
            $workflow['section_tasks'][$key]['telemetry_recorded'] = true;
            $telemetry[] = $this->generator->backgroundTelemetry($response);
        }
        if (! $allTerminal) {
            return [
                'workflow' => $workflow,
                'changes' => ['status' => 'in_progress', 'stage' => 'sections_draining', 'progress' => 0.8],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'workflow' => $workflow,
            'changes' => [],
            'telemetry' => $telemetry,
            'failure' => $workflow['pending_failure'],
        ];
    }

    /** @param array<string,mixed> $workflow @param array<string,mixed> $response @return array<string,mixed> */
    private function beginSectionDrain(array $workflow, string $taskKey, array $response, string $type, string $detail): array
    {
        $workflow['stage'] = 'sections_draining';
        $workflow['pending_failure'] = ['type' => $type, 'detail' => $detail];
        $workflow['section_tasks'][$taskKey]['telemetry_recorded'] = true;

        return [
            'workflow' => $workflow,
            'changes' => ['status' => 'in_progress', 'stage' => 'sections_draining', 'progress' => 0.8],
            'telemetry' => [$this->generator->backgroundTelemetry($response)],
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $workflow @return array<string,mixed> */
    private function advanceReview(array $payload, array $workflow): array
    {
        $response = $this->generator->retrieveBackground((string) $workflow['review_response_id']);
        if ($this->pending($response)) {
            return $this->pendingTransition($workflow, 'review', 0.9);
        }
        if (($response['status'] ?? null) !== 'completed') {
            return $this->failureTransition($workflow, $response, 'wireframe_review_failed', 'The whole-site wireframe review could not be completed.');
        }
        try {
            $reviewed = $this->generator->completeReviewBackground(
                $response,
                $workflow['assembled'],
                $payload['site_ast'],
                (string) $payload['locale'],
            );
        } catch (InvalidWireframeException $exception) {
            return $this->failureTransition($workflow, $response, 'invalid_wireframe_review', $exception->getMessage());
        }
        $workflow['stage'] = 'complete';

        return [
            'workflow' => $workflow,
            'changes' => [],
            'telemetry' => [$this->generator->backgroundTelemetry($response)],
            'result' => [
                'wireframe_ast' => $reviewed['wireframe'],
                'generation' => [
                    'mode' => 'section_parallel',
                    'section_count' => count($workflow['section_tasks']),
                    'review_finding_count' => $reviewed['review']['finding_count'],
                    'review_operation_count' => $reviewed['review']['operation_count'],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $response */
    private function pending(array $response): bool
    {
        return in_array($response['status'] ?? null, ['queued', 'in_progress'], true);
    }

    /** @param array<string,mixed> $workflow @return array<string,mixed> */
    private function pendingTransition(array $workflow, string $stage, float $progress): array
    {
        return [
            'workflow' => $workflow,
            'changes' => ['status' => 'in_progress', 'stage' => $stage, 'progress' => $progress],
            'telemetry' => [],
        ];
    }

    /** @param array<string,mixed> $workflow @param array<string,mixed> $response @return array<string,mixed> */
    private function failureTransition(array $workflow, array $response, string $type, string $detail): array
    {
        return [
            'workflow' => $workflow,
            'changes' => [],
            'telemetry' => [$this->generator->backgroundTelemetry($response)],
            'failure' => ['type' => $type, 'detail' => $detail],
        ];
    }
}
