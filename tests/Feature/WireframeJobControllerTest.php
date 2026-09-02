<?php

namespace Tests\Feature;

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
                'error' => ['message' => 'sensitive provider detail'],
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
            ->assertJsonPath('failure.type', 'wireframe_generation_failed');

        $this->assertStringNotContainsString('sensitive provider detail', (string) $response->getContent());
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
}
