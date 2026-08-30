<?php

namespace Tests\Unit\Services;

use App\Services\OpenAiTelemetry;
use PHPUnit\Framework\TestCase;

final class OpenAiTelemetryTest extends TestCase
{
    public function test_normalizes_and_aggregates_usage_without_double_charging_reasoning_tokens(): void
    {
        $telemetry = new OpenAiTelemetry($this->rateCard());
        $telemetry->beginSemanticAttempt();
        $telemetry->recordProviderRequest();
        $telemetry->recordProviderRequest();
        $telemetry->recordProviderDuration(1_500_000);
        $telemetry->recordResponse($this->response('resp_first', 100, 20, 40, 10));
        $telemetry->beginSemanticAttempt();
        $telemetry->recordProviderRequest();
        $telemetry->recordProviderDuration(2_500_000);
        $telemetry->recordResponse($this->response('resp_final', 200, 50, 80, 30));

        $result = $telemetry->toArray();

        $this->assertSame('openai', $result['provider']);
        $this->assertSame('gpt-5.6-sol', $result['model']);
        $this->assertSame('resp_final', $result['response_id']);
        $this->assertSame(300, $result['input_tokens']);
        $this->assertSame(70, $result['cached_input_tokens']);
        $this->assertSame(120, $result['output_tokens']);
        $this->assertSame(40, $result['reasoning_tokens']);
        $this->assertSame(0.003348, $result['estimated_cost']);
        $this->assertSame(3, $result['provider_request_count']);
        $this->assertSame(2, $result['semantic_attempt_count']);
        $this->assertSame(1, $result['retry_count']);
        $this->assertSame(4, $result['provider_duration_ms']);
        $this->assertSame([
            'version' => '2026-08-31',
            'effective_at' => '2026-08-31',
            'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-sol',
            'currency' => 'USD',
            'model' => 'gpt-5.6-sol',
        ], $result['rate_card']);
    }

    public function test_applies_long_context_multipliers_to_the_individual_response(): void
    {
        $telemetry = new OpenAiTelemetry($this->rateCard());
        $telemetry->beginSemanticAttempt();
        $telemetry->recordProviderRequest();
        $telemetry->recordResponse($this->response('resp_long', 272001, 72001, 100, 25));

        $result = $telemetry->toArray();

        $this->assertSame(1.6606008, $result['estimated_cost']);
        $this->assertSame(25, $result['reasoning_tokens']);
    }

    public function test_matches_an_explicitly_configured_dated_model_snapshot_to_its_rate_family(): void
    {
        $telemetry = new OpenAiTelemetry($this->rateCard());
        $telemetry->recordResponse($this->response('resp_snapshot', 100, 20, 40, 10, 'gpt-5.6-sol-2026-08-31'));

        $result = $telemetry->toArray();

        $this->assertSame('gpt-5.6-sol-2026-08-31', $result['model']);
        $this->assertSame(0.001128, $result['estimated_cost']);
        $this->assertSame('gpt-5.6-sol', $result['rate_card']['model']);

        $unknown = new OpenAiTelemetry($this->rateCard());
        $unknown->recordResponse($this->response('resp_unknown', 100, 20, 40, 10, 'gpt-5.6-sol-latest'));
        $this->assertNull($unknown->toArray()['estimated_cost']);
    }

    public function test_rejects_untrusted_identifiers_and_incomplete_usage(): void
    {
        $telemetry = new OpenAiTelemetry($this->rateCard());
        $telemetry->beginSemanticAttempt();
        $telemetry->recordProviderRequest();
        $telemetry->recordResponse([
            'id' => "resp_safe\napi-key-secret",
            'model' => 'gpt-5.6-sol api-key-secret',
            'usage' => [
                'input_tokens' => 10,
                'input_tokens_details' => ['cached_tokens' => 100],
                'output_tokens' => -1,
                'output_tokens_details' => ['reasoning_tokens' => 2],
            ],
        ]);

        $result = $telemetry->toArray();

        $this->assertNull($result['model']);
        $this->assertNull($result['response_id']);
        $this->assertSame(10, $result['input_tokens']);
        $this->assertSame(10, $result['cached_input_tokens']);
        $this->assertNull($result['output_tokens']);
        $this->assertSame(2, $result['reasoning_tokens']);
        $this->assertNull($result['estimated_cost']);
        $this->assertNull($result['rate_card']);
        $this->assertStringNotContainsString('api-key-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_does_not_expose_credentials_embedded_in_rate_card_metadata(): void
    {
        $rateCard = $this->rateCard();
        $rateCard['source'] = 'https://api-key-secret@developers.openai.com/api/docs/models/gpt-5.6-sol';
        $telemetry = new OpenAiTelemetry($rateCard);
        $telemetry->recordResponse($this->response('resp_safe', 10, 0, 5, 1));

        $result = $telemetry->toArray();

        $this->assertNull($result['rate_card']);
        $this->assertNull($result['estimated_cost']);
        $this->assertStringNotContainsString('api-key-secret', json_encode($result, JSON_THROW_ON_ERROR));
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

    /** @return array<string,mixed> */
    private function response(
        string $id,
        int $input,
        int $cached,
        int $output,
        int $reasoning,
        string $model = 'gpt-5.6-sol',
    ): array {
        return [
            'id' => $id,
            'model' => $model,
            'usage' => [
                'input_tokens' => $input,
                'input_tokens_details' => ['cached_tokens' => $cached],
                'output_tokens' => $output,
                'output_tokens_details' => ['reasoning_tokens' => $reasoning],
            ],
        ];
    }
}
