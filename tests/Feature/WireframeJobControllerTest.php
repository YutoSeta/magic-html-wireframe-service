<?php

namespace Tests\Feature;

use App\Services\OpenAiWireframeGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\WireframeV2Fixture;
use Tests\TestCase;

final class WireframeJobControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'wireframe.service_token' => 'test-token',
            'wireframe.idempotency.store' => 'array',
            'services.openai.key' => 'test-openai-api-key',
            'services.openai.url' => 'https://api.openai.test/v1/responses',
            'services.openai.retry_delays_ms' => [],
        ]);
        Cache::store('array')->clear();
        Http::preventStrayRequests();
    }

    public function test_background_generation_is_polled_to_a_succeeded_encrypted_job(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_background_1',
                'status' => 'queued',
            ]),
            'https://api.openai.test/v1/responses/resp_background_1' => Http::sequence()
                ->push(['id' => 'resp_background_1', 'status' => 'in_progress'])
                ->push($this->completedProviderResponse()),
        ]);

        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->payload())
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('progress', 0.05)
            ->assertJsonMissingPath('result')
            ->assertJsonMissingPath('context_encrypted')
            ->assertJsonMissingPath('provider_response_id');

        $jobId = (string) $started->json('id');
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/iD', $jobId);

        $this->withToken('test-token')
            ->getJson("/api/v1/wireframe-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('progress', 0.5);

        $this->withToken('test-token')
            ->getJson("/api/v1/wireframe-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('progress', 1)
            ->assertJsonPath('result.wireframe_ast.version', 2)
            ->assertJsonPath('result.wireframe_ast.pages.0.root.semantic', 'document')
            ->assertJsonPath('result.telemetry.provider', 'openai')
            ->assertJsonPath('result.telemetry.response_id', 'resp_background_1')
            ->assertJsonPath('result.telemetry.input_tokens', 1200)
            ->assertJsonPath('result.telemetry.output_tokens', 900)
            ->assertJsonMissingPath('context_encrypted')
            ->assertJsonMissingPath('provider_response_id');

        $record = Cache::store('array')->get("wireframe-job:{$jobId}");
        $this->assertIsArray($record);
        $this->assertIsString($record['context_encrypted']);
        $this->assertIsString($record['result_encrypted']);
        $serialized = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('作り直す前に', $serialized);
        $this->assertStringNotContainsString('無料相談を送信する', $serialized);
        $this->assertStringNotContainsString('test-openai-api-key', $serialized);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.openai.test/v1/responses' || $request->method() !== 'POST') {
                return false;
            }

            return $request['background'] === true
                && $request['store'] === false
                && $request['model'] === 'gpt-5.6-luna'
                && $request['reasoning']['effort'] === 'low'
                && $request['metadata']['execution_profile'] === 'fast'
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema']['properties']['version']['type'] === 'integer';
        });
    }

    public function test_exact_replay_reuses_the_job_and_changed_payload_conflicts(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_background_replay',
                'status' => 'queued',
            ]),
        ]);
        $request = fn (array $payload) => $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-replay-0001')
            ->postJson('/api/v1/wireframe-jobs', $payload);

        $first = $request($this->payload())->assertAccepted();
        $second = $request($this->payload())->assertAccepted();
        $this->assertSame($first->json('id'), $second->json('id'));

        $changed = $this->payload();
        $changed['brief']['goals'] = '採用応募の増加';
        $request($changed)
            ->assertConflict()
            ->assertJsonPath('type', 'idempotency_conflict');

        Http::assertSentCount(1);
    }

    public function test_terminal_provider_failure_is_normalized_without_exposing_provider_details(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_background_failed',
                'status' => 'queued',
            ]),
            'https://api.openai.test/v1/responses/resp_background_failed' => Http::response([
                'id' => 'resp_background_failed',
                'status' => 'failed',
                'model' => 'gpt-5.6-luna',
                'error' => ['message' => 'sensitive provider detail'],
                'usage' => [
                    'input_tokens' => 100,
                    'input_tokens_details' => ['cached_tokens' => 10],
                    'output_tokens' => 20,
                    'output_tokens_details' => ['reasoning_tokens' => 5],
                ],
            ]),
        ]);

        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-failed-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->payload())
            ->assertAccepted();

        $response = $this->withToken('test-token')
            ->getJson('/api/v1/wireframe-jobs/'.$started->json('id'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('failure.type', 'wireframe_generation_failed')
            ->assertJsonPath('telemetry.output_tokens', 20)
            ->assertJsonPath('telemetry.semantic_attempt_count', 1);

        $this->assertStringNotContainsString('sensitive provider detail', (string) $response->getContent());
    }

    public function test_output_limit_starts_one_bounded_concise_retry_and_aggregates_usage(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_background_output_limit', 'status' => 'queued'])
                ->push(['id' => 'resp_background_concise', 'status' => 'queued']),
            'https://api.openai.test/v1/responses/resp_background_output_limit' => Http::response(
                $this->outputLimitProviderResponse('resp_background_output_limit'),
            ),
            'https://api.openai.test/v1/responses/resp_background_concise' => Http::response(
                $this->completedProviderResponse('resp_background_concise'),
            ),
        ]);

        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-output-limit-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->payload())
            ->assertAccepted();
        $path = '/api/v1/wireframe-jobs/'.$started->json('id');

        $this->withToken('test-token')
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('semantic_attempt', 2);

        $this->withToken('test-token')
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('result.telemetry.response_id', 'resp_background_concise')
            ->assertJsonPath('result.telemetry.input_tokens', 3288)
            ->assertJsonPath('result.telemetry.output_tokens', 64900)
            ->assertJsonPath('result.telemetry.reasoning_tokens', 205)
            ->assertJsonPath('result.telemetry.provider_request_count', 2)
            ->assertJsonPath('result.telemetry.semantic_attempt_count', 2);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.openai.test/v1/responses' || $request->method() !== 'POST') {
                return false;
            }
            $input = json_decode((string) $request['input'], true);

            return str_contains((string) ($input['validation_feedback'] ?? ''), 'exhausted the output-token limit')
                && str_contains((string) $request['instructions'], 'at most 120 nodes per page');
        });
        Http::assertSentCount(4);
    }

    public function test_second_output_limit_fails_with_specific_reason_and_complete_usage(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_background_output_limit_1', 'status' => 'queued'])
                ->push(['id' => 'resp_background_output_limit_2', 'status' => 'queued']),
            'https://api.openai.test/v1/responses/resp_background_output_limit_1' => Http::response(
                $this->outputLimitProviderResponse('resp_background_output_limit_1'),
            ),
            'https://api.openai.test/v1/responses/resp_background_output_limit_2' => Http::response(
                $this->outputLimitProviderResponse('resp_background_output_limit_2'),
            ),
        ]);

        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-output-limit-twice-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->payload())
            ->assertAccepted();
        $path = '/api/v1/wireframe-jobs/'.$started->json('id');
        $this->withToken('test-token')
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('semantic_attempt', 2);

        $this->withToken('test-token')
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('failure.type', 'wireframe_output_limit_exceeded')
            ->assertJsonPath('telemetry.input_tokens', 4176)
            ->assertJsonPath('telemetry.output_tokens', 128000)
            ->assertJsonPath('telemetry.reasoning_tokens', 210)
            ->assertJsonPath('telemetry.provider_request_count', 2)
            ->assertJsonPath('telemetry.semantic_attempt_count', 2);

        Http::assertSentCount(4);
    }

    public function test_transient_poll_failure_keeps_the_job_resumable(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_background_transient',
                'status' => 'queued',
            ]),
            'https://api.openai.test/v1/responses/resp_background_transient' => Http::sequence()
                ->push(['error' => ['type' => 'server_error']], 500)
                ->push($this->completedProviderResponse('resp_background_transient')),
        ]);

        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-transient-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->payload())
            ->assertAccepted();
        $path = '/api/v1/wireframe-jobs/'.$started->json('id');

        $this->withToken('test-token')
            ->getJson($path)
            ->assertStatus(502)
            ->assertJsonPath('type', 'wireframe_provider_failed');

        $this->withToken('test-token')
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded');
    }

    public function test_invalid_semantic_result_starts_one_bounded_background_repair_and_aggregates_usage(): void
    {
        $invalidDocument = WireframeV2Fixture::document();
        $invalidDocument['pages'][0]['title'] = 'Site ASTと一致しないタイトル';
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_background_invalid', 'status' => 'queued'])
                ->push(['id' => 'resp_background_repair', 'status' => 'queued']),
            'https://api.openai.test/v1/responses/resp_background_invalid' => Http::response(
                $this->completedProviderResponse('resp_background_invalid', $invalidDocument),
            ),
            'https://api.openai.test/v1/responses/resp_background_repair' => Http::response(
                $this->completedProviderResponse('resp_background_repair'),
            ),
        ]);

        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-repair-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->payload())
            ->assertAccepted();
        $path = '/api/v1/wireframe-jobs/'.$started->json('id');

        $this->withToken('test-token')
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('semantic_attempt', 2)
            ->assertJsonMissingPath('telemetry');

        $this->withToken('test-token')
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('semantic_attempt', 2)
            ->assertJsonPath('result.telemetry.response_id', 'resp_background_repair')
            ->assertJsonPath('result.telemetry.input_tokens', 2400)
            ->assertJsonPath('result.telemetry.cached_input_tokens', 400)
            ->assertJsonPath('result.telemetry.output_tokens', 1800)
            ->assertJsonPath('result.telemetry.reasoning_tokens', 200)
            ->assertJsonPath('result.telemetry.provider_request_count', 2)
            ->assertJsonPath('result.telemetry.semantic_attempt_count', 2);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.openai.test/v1/responses' || $request->method() !== 'POST') {
                return false;
            }
            $input = json_decode((string) $request['input'], true);

            return ($input['validation_feedback'] ?? null) === 'Wireframe page titles must match the Site AST.';
        });
        Http::assertSentCount(4);
    }

    public function test_second_invalid_semantic_result_fails_without_starting_an_unbounded_retry(): void
    {
        $invalidDocument = WireframeV2Fixture::document();
        $invalidDocument['pages'][0]['title'] = 'Site ASTと一致しないタイトル';
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_background_invalid_1', 'status' => 'queued'])
                ->push(['id' => 'resp_background_invalid_2', 'status' => 'queued']),
            'https://api.openai.test/v1/responses/resp_background_invalid_1' => Http::response(
                $this->completedProviderResponse('resp_background_invalid_1', $invalidDocument),
            ),
            'https://api.openai.test/v1/responses/resp_background_invalid_2' => Http::response(
                $this->completedProviderResponse('resp_background_invalid_2', $invalidDocument),
            ),
        ]);

        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-invalid-twice-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->payload())
            ->assertAccepted();
        $path = '/api/v1/wireframe-jobs/'.$started->json('id');
        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('semantic_attempt', 2);

        $this->withToken('test-token')
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('failure.type', 'invalid_wireframe')
            ->assertJsonPath('telemetry.input_tokens', 2400)
            ->assertJsonPath('telemetry.semantic_attempt_count', 2);

        Http::assertSentCount(4);
    }

    public function test_job_routes_require_authentication_and_a_valid_request(): void
    {
        $this->postJson('/api/v1/wireframe-jobs', $this->payload())->assertUnauthorized();
        $payload = $this->payload();
        $payload['site_ast'] = [];
        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'background-wireframe-invalid-0001')
            ->postJson('/api/v1/wireframe-jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_ast');
        $this->withToken('test-token')
            ->getJson('/api/v1/wireframe-jobs/not-a-job')
            ->assertNotFound()
            ->assertJsonPath('type', 'job_not_found');
    }

    public function test_section_parallel_mode_plans_generates_sections_in_parallel_and_applies_whole_site_review(): void
    {
        config()->set('wireframe.jobs.section_start_batch', 6);
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_plan', 'status' => 'queued'])
                ->push(['id' => 'resp_section_hero', 'status' => 'queued'])
                ->push(['id' => 'resp_section_contact', 'status' => 'queued'])
                ->push(['id' => 'resp_review', 'status' => 'queued']),
            'https://api.openai.test/v1/responses/resp_plan' => Http::response(
                $this->structuredProviderResponse('resp_plan', $this->sectionPlan()),
            ),
            'https://api.openai.test/v1/responses/resp_section_hero' => Http::response(
                $this->structuredProviderResponse('resp_section_hero', $this->heroSection()),
            ),
            'https://api.openai.test/v1/responses/resp_section_contact' => Http::response(
                $this->structuredProviderResponse('resp_section_contact', $this->contactSection()),
            ),
            'https://api.openai.test/v1/responses/resp_review' => Http::response(
                $this->structuredProviderResponse('resp_review', [
                    'version' => 1,
                    'findings' => [[
                        'code' => 'clarity', 'severity' => 'warning', 'page_key' => 'home',
                        'node_id' => 'hero-body', 'detail' => '導入判断の対象を明確にする。',
                    ]],
                    'operations' => [[
                        'op' => 'replace_copy', 'page_key' => 'home', 'node_id' => 'hero-body',
                        'property' => 'content', 'value' => '導入判断に必要な機能・費用・進め方を一冊で確認できます。',
                    ], [
                        'op' => 'reorder_sections', 'page_key' => 'home',
                        'section_ids' => ['hero-section', 'contact-section'],
                    ], [
                        'op' => 'replace_copy', 'page_key' => 'home', 'node_id' => 'hero-section',
                        'property' => 'content', 'value' => 'Regionへは適用できないため棄却される操作',
                    ]],
                ]),
            ),
        ]);
        $payload = $this->sectionParallelPayload();
        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'section-parallel-wireframe-0001')
            ->postJson('/api/v1/wireframe-jobs', $payload)
            ->assertAccepted()
            ->assertJsonPath('generation_mode', 'section_parallel')
            ->assertJsonPath('stage', 'planning');
        $path = '/api/v1/wireframe-jobs/'.$started->json('id');

        $this->withToken('test-token')->getJson($path)
            ->assertOk()->assertJsonPath('stage', 'sections')->assertJsonPath('progress', 0.15);
        $this->withToken('test-token')->getJson($path)
            ->assertOk()->assertJsonPath('stage', 'sections')->assertJsonPath('progress', 0.15);
        $this->withToken('test-token')->getJson($path)
            ->assertOk()->assertJsonPath('stage', 'review')->assertJsonPath('progress', 0.85);
        $result = $this->withToken('test-token')->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('stage', 'complete')
            ->assertJsonPath('result.wireframe_ast.version', 2)
            ->assertJsonPath('result.wireframe_ast.pages.0.root.children.1.children.0.id', 'hero-section')
            ->assertJsonPath('result.wireframe_ast.pages.0.root.children.1.children.1.id', 'contact-section')
            ->assertJsonPath('result.wireframe_ast.pages.0.root.children.1.children.0.children.1.content', '導入判断に必要な機能・費用・進め方を一冊で確認できます。')
            ->assertJsonPath('result.generation.mode', 'section_parallel')
            ->assertJsonPath('result.generation.section_count', 2)
            ->assertJsonPath('result.generation.review_finding_count', 1)
            ->assertJsonPath('result.generation.review_operation_count', 2)
            ->assertJsonPath('result.generation.review_skipped_operation_count', 1)
            ->assertJsonPath('result.telemetry.provider_request_count', 4)
            ->assertJsonPath('result.telemetry.semantic_attempt_count', 4)
            ->assertJsonPath('result.telemetry.input_tokens', 400)
            ->assertJsonPath('result.telemetry.output_tokens', 200);
        $this->assertStringNotContainsString('workflow_encrypted', (string) $result->getContent());

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.openai.test/v1/responses' || $request->method() !== 'POST') {
                return false;
            }

            return ($request['metadata']['stage'] ?? null) === 'wireframe_review'
                && str_contains((string) $request['instructions'], 'never regenerate the whole AST')
                && ($request['text']['format']['schema']['properties']['operations']['maxItems'] ?? null) === 40;
        });
        Http::assertSentCount(8);
    }

    public function test_section_parallel_mode_requires_v2_and_the_async_endpoint(): void
    {
        $payload = $this->payload();
        $payload['wireframe_ast_version'] = 1;
        $payload['generation_mode'] = 'section_parallel';
        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'section-parallel-v1-invalid-0001')
            ->postJson('/api/v1/wireframe-jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('generation_mode');

        $payload = $this->sectionParallelPayload();
        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'section-parallel-sync-invalid-0001')
            ->postJson('/api/v1/wireframes', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'generation_mode_requires_async');
    }

    public function test_section_parallel_mode_repairs_only_the_invalid_section_once(): void
    {
        $invalidHero = $this->heroSection();
        array_shift($invalidHero['section']['children']);
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_plan_repair', 'status' => 'queued'])
                ->push(['id' => 'resp_section_hero_invalid', 'status' => 'queued'])
                ->push(['id' => 'resp_section_contact_valid', 'status' => 'queued'])
                ->push(['id' => 'resp_section_hero_repaired', 'status' => 'queued'])
                ->push(['id' => 'resp_review_repair', 'status' => 'queued']),
            'https://api.openai.test/v1/responses/resp_plan_repair' => Http::response(
                $this->structuredProviderResponse('resp_plan_repair', $this->sectionPlan()),
            ),
            'https://api.openai.test/v1/responses/resp_section_hero_invalid' => Http::response(
                $this->structuredProviderResponse('resp_section_hero_invalid', $invalidHero),
            ),
            'https://api.openai.test/v1/responses/resp_section_contact_valid' => Http::response(
                $this->structuredProviderResponse('resp_section_contact_valid', $this->contactSection()),
            ),
            'https://api.openai.test/v1/responses/resp_section_hero_repaired' => Http::response(
                $this->structuredProviderResponse('resp_section_hero_repaired', $this->heroSection()),
            ),
            'https://api.openai.test/v1/responses/resp_review_repair' => Http::response(
                $this->structuredProviderResponse('resp_review_repair', ['version' => 1, 'findings' => [], 'operations' => []]),
            ),
        ]);
        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'section-parallel-repair-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->sectionParallelPayload())
            ->assertAccepted();
        $path = '/api/v1/wireframe-jobs/'.$started->json('id');

        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('stage', 'sections');
        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('stage', 'sections');
        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('stage', 'sections');
        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('stage', 'review');
        $this->withToken('test-token')->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('result.telemetry.provider_request_count', 5)
            ->assertJsonPath('result.telemetry.semantic_attempt_count', 5)
            ->assertJsonPath('result.telemetry.input_tokens', 500);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.openai.test/v1/responses' || $request->method() !== 'POST') {
                return false;
            }
            $input = json_decode((string) $request['input'], true);

            return str_contains((string) ($input['validation_feedback'] ?? ''), 'heading-1 expected 1, received 0');
        });
        Http::assertSentCount(10);
    }

    public function test_section_completion_deterministically_supplies_a_planned_missing_image_leaf(): void
    {
        $document = $this->heroSection();
        array_splice($document['section']['children'], 2, 1);
        $pagePlan = $this->sectionPlan()['pages'][0];
        $sectionPlan = $pagePlan['sections'][0];

        $section = app(OpenAiWireframeGenerator::class)->completeSectionBackground(
            $this->structuredProviderResponse('resp_missing_image', $document),
            $pagePlan,
            $sectionPlan,
        );

        $this->assertSame('Image', data_get($section, 'children.3.type'));
        $this->assertSame('hero-section-image', data_get($section, 'children.3.id'));
        $this->assertSame('価値提案と資料請求への導入', data_get($section, 'children.3.alt'));
    }

    public function test_section_completion_deterministically_supplies_a_planned_missing_form(): void
    {
        $document = $this->contactSection();
        array_pop($document['section']['children']);
        $pagePlan = $this->sectionPlan()['pages'][0];
        $sectionPlan = $pagePlan['sections'][1];

        $section = app(OpenAiWireframeGenerator::class)->completeSectionBackground(
            $this->structuredProviderResponse('resp_missing_form', $document),
            $pagePlan,
            $sectionPlan,
        );

        $this->assertSame('form', data_get($section, 'children.1.semantic'));
        $this->assertSame('Input', data_get($section, 'children.1.controls.0.type'));
        $this->assertSame('email', data_get($section, 'children.1.controls.0.input_type'));
        $this->assertSame('submit', data_get($section, 'children.1.submit.button_type'));
    }

    public function test_section_parallel_mode_retries_only_a_terminal_provider_section_once(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_plan_provider_retry', 'status' => 'queued'])
                ->push(['id' => 'resp_section_hero_limited', 'status' => 'queued'])
                ->push(['id' => 'resp_section_contact_provider_valid', 'status' => 'queued'])
                ->push(['id' => 'resp_section_hero_provider_repaired', 'status' => 'queued'])
                ->push(['id' => 'resp_review_provider_retry', 'status' => 'queued']),
            'https://api.openai.test/v1/responses/resp_plan_provider_retry' => Http::response(
                $this->structuredProviderResponse('resp_plan_provider_retry', $this->sectionPlan()),
            ),
            'https://api.openai.test/v1/responses/resp_section_hero_limited' => Http::response(
                $this->outputLimitProviderResponse('resp_section_hero_limited'),
            ),
            'https://api.openai.test/v1/responses/resp_section_contact_provider_valid' => Http::response(
                $this->structuredProviderResponse('resp_section_contact_provider_valid', $this->contactSection()),
            ),
            'https://api.openai.test/v1/responses/resp_section_hero_provider_repaired' => Http::response(
                $this->structuredProviderResponse('resp_section_hero_provider_repaired', $this->heroSection()),
            ),
            'https://api.openai.test/v1/responses/resp_review_provider_retry' => Http::response(
                $this->structuredProviderResponse('resp_review_provider_retry', ['version' => 1, 'findings' => [], 'operations' => []]),
            ),
        ]);
        $started = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'section-parallel-provider-retry-0001')
            ->postJson('/api/v1/wireframe-jobs', $this->sectionParallelPayload())
            ->assertAccepted();
        $path = '/api/v1/wireframe-jobs/'.$started->json('id');

        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('stage', 'sections');
        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('stage', 'sections');
        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('stage', 'sections');
        $this->withToken('test-token')->getJson($path)->assertOk()->assertJsonPath('stage', 'review');
        $this->withToken('test-token')->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('result.telemetry.provider_request_count', 5)
            ->assertJsonPath('result.telemetry.input_tokens', 2488)
            ->assertJsonPath('result.telemetry.output_tokens', 64200);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.openai.test/v1/responses' || $request->method() !== 'POST') {
                return false;
            }
            $input = json_decode((string) $request['input'], true);

            return str_contains((string) ($input['validation_feedback'] ?? ''), 'exhausted the output-token limit');
        });
        Http::assertSentCount(10);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'contract_version' => '1.0',
            'wireframe_ast_version' => 2,
            'site_ast' => WireframeV2Fixture::siteAst(),
            'brief' => [
                'organization' => 'ウェブ修理工房',
                'goals' => '無料相談の獲得',
                'audience' => 'Webサイトに不具合を抱える事業者',
                'tone' => '誠実で信頼できる',
                'requirements' => 'フォームと法的ページを含める',
                'materials' => [],
            ],
            'locale' => 'ja',
        ];
    }

    /** @return array<string,mixed> */
    private function sectionParallelPayload(): array
    {
        return [
            'contract_version' => '1.0',
            'wireframe_ast_version' => 2,
            'generation_mode' => 'section_parallel',
            'execution_profile' => 'fast',
            'site_ast' => [
                'version' => 1,
                'site' => ['name' => 'Schema Test', 'description' => '資料請求LP'],
                'pages' => [['key' => 'home', 'path' => '/', 'title' => 'Schema Test', 'purpose' => '資料請求']],
                'navigation' => [['label' => 'ホーム', 'path' => '/']],
            ],
            'brief' => [
                'organization' => 'Schema Test',
                'goals' => '資料請求',
                'audience' => '中小企業のIT担当者',
                'tone' => '簡潔で信頼感',
                'requirements' => '資料請求フォームを含める',
                'materials' => [],
            ],
            'locale' => 'ja',
        ];
    }

    /** @return array<string,mixed> */
    private function sectionPlan(): array
    {
        return [
            'version' => 1,
            'pages' => [[
                'key' => 'home', 'path' => '/', 'title' => 'Schema Test',
                'sections' => [[
                    'id' => 'hero-section', 'purpose' => '価値提案と資料請求への導入',
                    'journey_stage' => 'attention', 'layout' => 'split', 'emphasis' => 'primary',
                    'contains_heading_1' => true, 'requires_form' => false, 'requires_image' => true,
                ], [
                    'id' => 'contact-section', 'purpose' => '資料請求フォーム',
                    'journey_stage' => 'action', 'layout' => 'stack', 'emphasis' => 'primary',
                    'contains_heading_1' => false, 'requires_form' => true, 'requires_image' => false,
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function heroSection(): array
    {
        return [
            'version' => 1, 'page_key' => 'home', 'section_id' => 'hero-section',
            'section' => WireframeV2Fixture::region('hero-section', 'section', 'split', 'attention', 'primary', [
                WireframeV2Fixture::text('hero-title', 'heading-1', 'IT業務を整理する資料をご用意しました'),
                WireframeV2Fixture::text('hero-body', 'body', '導入判断に必要な情報を一冊で確認できます。'),
                WireframeV2Fixture::image('hero-image', '資料の内容を示す画面', null, '4:3'),
                WireframeV2Fixture::link('hero-contact-link', '資料を請求する', '#contact-section', 'primary'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function contactSection(): array
    {
        return [
            'version' => 1, 'page_key' => 'home', 'section_id' => 'contact-section',
            'section' => [
                'type' => 'Region', 'id' => 'contact-section', 'semantic' => 'section',
                'layout' => 'stack', 'journey_stage' => 'action', 'emphasis' => 'primary', 'children' => [[
                    'type' => 'Text', 'id' => 'contact-title', 'role' => 'heading-2', 'content' => '資料請求',
                ], [
                    'type' => 'Region', 'id' => 'contact-form', 'semantic' => 'form',
                    'layout' => 'stack', 'journey_stage' => 'action', 'emphasis' => 'primary',
                    'content' => [[
                        'type' => 'Text', 'id' => 'contact-form-note', 'role' => 'body', 'content' => '必要事項をご入力ください。',
                    ]],
                    'controls' => [[
                        'type' => 'Input', 'id' => 'contact-email', 'input_type' => 'email',
                        'label' => 'メールアドレス', 'name' => 'email', 'placeholder' => 'name@example.jp', 'required' => true,
                    ]],
                    'submit' => [
                        'type' => 'Button', 'id' => 'contact-submit', 'label' => '資料を請求する',
                        'button_type' => 'submit', 'emphasis' => 'primary',
                    ],
                ]],
            ],
        ];
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function structuredProviderResponse(string $id, array $document): array
    {
        return [
            'id' => $id,
            'status' => 'completed',
            'model' => 'gpt-5.6-luna',
            'output_text' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'usage' => [
                'input_tokens' => 100,
                'input_tokens_details' => ['cached_tokens' => 20],
                'output_tokens' => 50,
                'output_tokens_details' => ['reasoning_tokens' => 10],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function completedProviderResponse(string $id = 'resp_background_1', ?array $document = null): array
    {
        return [
            'id' => $id,
            'status' => 'completed',
            'model' => 'gpt-5.6',
            'output_text' => json_encode($document ?? WireframeV2Fixture::document(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'usage' => [
                'input_tokens' => 1200,
                'input_tokens_details' => ['cached_tokens' => 200],
                'output_tokens' => 900,
                'output_tokens_details' => ['reasoning_tokens' => 100],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function outputLimitProviderResponse(string $id): array
    {
        return [
            'id' => $id,
            'status' => 'incomplete',
            'model' => 'gpt-5.6-luna',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'usage' => [
                'input_tokens' => 2088,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens' => 64000,
                'output_tokens_details' => ['reasoning_tokens' => 105],
            ],
        ];
    }
}
