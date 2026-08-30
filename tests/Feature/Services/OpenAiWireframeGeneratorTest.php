<?php

namespace Tests\Feature\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiWireframeGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'wireframe.service_token' => 'test-token',
            'wireframe.idempotency.store' => 'array',
            'services.openai.key' => 'test-openai-api-key',
            'services.openai.url' => 'https://api.openai.test/v1/responses',
            'services.openai.model' => 'gpt-5.6',
            'services.openai.retry_delays_ms' => [],
            'services.openai.rate_card' => $this->rateCard(),
        ]);
    }

    public function test_returns_aggregated_provider_telemetry_and_reports_a_zero_cost_idempotent_replay(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push($this->providerResponse('resp_invalid', $this->wireframe('other'), 100, 20, 40, 10))
                ->push($this->providerResponse('resp_final', $this->wireframe('home'), 200, 50, 80, 30)),
        ]);

        $first = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-telemetry-replay-0001')
            ->postJson('/api/v1/wireframes', $this->payload());
        $replay = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-telemetry-replay-0001')
            ->postJson('/api/v1/wireframes', $this->payload());

        $first->assertOk()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('telemetry.provider', 'openai')
            ->assertJsonPath('telemetry.model', 'gpt-5.6-sol-2026-08-31')
            ->assertJsonPath('telemetry.response_id', 'resp_final')
            ->assertJsonPath('telemetry.input_tokens', 300)
            ->assertJsonPath('telemetry.cached_input_tokens', 70)
            ->assertJsonPath('telemetry.output_tokens', 120)
            ->assertJsonPath('telemetry.reasoning_tokens', 40)
            ->assertJsonPath('telemetry.estimated_cost', 0.003348)
            ->assertJsonPath('telemetry.provider_request_count', 2)
            ->assertJsonPath('telemetry.semantic_attempt_count', 2)
            ->assertJsonPath('telemetry.retry_count', 0)
            ->assertJsonPath('telemetry.idempotent_replayed', false)
            ->assertJsonPath('telemetry.generation_telemetry_reference.algorithm', 'sha256')
            ->assertJsonPath('telemetry.rate_card.currency', 'USD')
            ->assertJsonPath('telemetry.rate_card.model', 'gpt-5.6-sol');
        $this->assertIsInt($first->json('telemetry.provider_duration_ms'));
        $this->assertGreaterThanOrEqual(0, $first->json('telemetry.provider_duration_ms'));
        $generationDigest = $first->json('telemetry.generation_telemetry_reference.digest');
        $this->assertIsString($generationDigest);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $generationDigest);

        $replay->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('wireframe_ast', $first->json('wireframe_ast'))
            ->assertJsonPath('telemetry.provider', 'idempotency_store')
            ->assertJsonPath('telemetry.model', null)
            ->assertJsonPath('telemetry.response_id', null)
            ->assertJsonPath('telemetry.input_tokens', 0)
            ->assertJsonPath('telemetry.cached_input_tokens', 0)
            ->assertJsonPath('telemetry.output_tokens', 0)
            ->assertJsonPath('telemetry.reasoning_tokens', 0)
            ->assertJsonPath('telemetry.estimated_cost', 0)
            ->assertJsonPath('telemetry.provider_request_count', 0)
            ->assertJsonPath('telemetry.semantic_attempt_count', 0)
            ->assertJsonPath('telemetry.retry_count', 0)
            ->assertJsonPath('telemetry.provider_duration_ms', 0)
            ->assertJsonPath('telemetry.rate_card', null)
            ->assertJsonPath('telemetry.idempotent_replayed', true)
            ->assertJsonPath('telemetry.generation_telemetry_reference.algorithm', 'sha256')
            ->assertJsonPath('telemetry.generation_telemetry_reference.digest', $generationDigest);
        Http::assertSentCount(2);

        $record = Cache::get('wireframe-idempotency:'.hash('sha256', 'openai-telemetry-replay-0001'));
        $serialized = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('test-openai-api-key', $serialized);
        $this->assertStringNotContainsString('Web工房', $serialized);
        $this->assertStringNotContainsString('問い合わせ増加', $serialized);
        $this->assertStringNotContainsString('resp_invalid', $serialized);
    }

    public function test_counts_transport_retries_separately_from_semantic_attempts(): void
    {
        config()->set('services.openai.retry_delays_ms', [0]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->pushStatus(500)
                ->push($this->providerResponse('resp_retried', $this->wireframe('home'), 10, 0, 5, 1)),
        ]);

        $response = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-transport-retry-0001')
            ->postJson('/api/v1/wireframes', $this->payload());

        $response->assertOk()
            ->assertJsonPath('telemetry.provider_request_count', 2)
            ->assertJsonPath('telemetry.semantic_attempt_count', 1)
            ->assertJsonPath('telemetry.retry_count', 1);
        Http::assertSentCount(2);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'contract_version' => '1.0',
            'site_ast' => [
                'version' => 1,
                'site' => ['name' => 'Web工房', 'description' => 'Description'],
                'pages' => [['key' => 'home', 'path' => '/', 'title' => 'Home', 'purpose' => 'Top']],
                'navigation' => [['label' => 'Home', 'path' => '/']],
            ],
            'brief' => [
                'organization' => 'Web工房',
                'goals' => '問い合わせ増加',
                'audience' => '中小企業',
                'tone' => '誠実',
                'requirements' => '5ページ',
                'materials' => [],
            ],
            'locale' => 'ja',
        ];
    }

    /** @return array<string,mixed> */
    private function providerResponse(string $id, array $wireframe, int $input, int $cached, int $output, int $reasoning): array
    {
        return [
            'id' => $id,
            'model' => 'gpt-5.6-sol-2026-08-31',
            'output_text' => json_encode($wireframe, JSON_THROW_ON_ERROR),
            'usage' => [
                'input_tokens' => $input,
                'input_tokens_details' => ['cached_tokens' => $cached],
                'output_tokens' => $output,
                'output_tokens_details' => ['reasoning_tokens' => $reasoning],
                'total_tokens' => $input + $output,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function wireframe(string $pageKey): array
    {
        return [
            'pages' => [[
                'key' => $pageKey,
                'sections' => [
                    ['key' => 'hero', 'composition' => 'hero', 'roles' => ['Title', 'Text', 'Actions']],
                    ['key' => 'cta', 'composition' => 'cta', 'roles' => ['Title', 'Text', 'Actions']],
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function rateCard(): array
    {
        return [
            'version' => '2026-08-31',
            'effective_at' => '2026-08-31',
            'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-sol',
            'currency' => 'USD',
            'models' => [
                'gpt-5.6-sol' => [
                    'aliases' => ['gpt-5.6'],
                    'snapshot_prefixes' => ['gpt-5.6-sol-'],
                    'input_per_million' => 4,
                    'cached_input_per_million' => 0.4,
                    'output_per_million' => 20,
                    'long_context_threshold_tokens' => 272000,
                    'long_context_input_multiplier' => 2,
                    'long_context_output_multiplier' => 1.5,
                ],
            ],
        ];
    }
}
