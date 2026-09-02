<?php

namespace Tests\Feature\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\WireframeV2Fixture;
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

    public function test_returns_502_and_logs_only_sanitized_metadata_when_provider_rejects_request(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'error' => [
                    'message' => 'Sensitive upstream detail must not be logged.',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_value',
                    'param' => 'max_output_tokens',
                ],
            ], 400, ['x-request-id' => 'req_safe_123']),
        ]);
        Log::spy();

        $response = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-provider-rejected-0001')
            ->postJson('/api/v1/wireframes', $this->payload());

        $response->assertStatus(502)
            ->assertJsonPath('type', 'wireframe_provider_failed')
            ->assertJsonMissing(['detail' => 'Sensitive upstream detail must not be logged.']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Wireframe provider rejected request.', Mockery::on(fn (array $context): bool => $context === [
                'provider' => 'openai',
                'model' => 'gpt-5.6-luna',
                'status' => 400,
                'request_id' => 'req_safe_123',
                'error_type' => 'invalid_request_error',
                'error_code' => 'invalid_value',
                'error_param' => 'max_output_tokens',
            ]));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['model'] === 'gpt-5.6-luna'
            && $request['reasoning']['effort'] === 'low'
            && $request['metadata']['execution_profile'] === 'fast');
    }

    public function test_v1_invalid_json_keeps_the_single_call_provider_failure_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_v1_invalid_json',
                'model' => 'gpt-5.6-sol-2026-08-31',
                'output_text' => '{',
                'usage' => [
                    'input_tokens' => 10,
                    'input_tokens_details' => ['cached_tokens' => 0],
                    'output_tokens' => 1,
                    'output_tokens_details' => ['reasoning_tokens' => 0],
                    'total_tokens' => 11,
                ],
            ]),
        ]);

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-wireframe-v1-invalid-json-0001')
            ->postJson('/api/v1/wireframes', $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('type', 'wireframe_provider_failed');

        Http::assertSentCount(1);
    }

    public function test_v2_requests_a_finite_content_bearing_ast_without_provider_owned_decoration(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push($this->providerResponse('resp_v2', $this->providerDocument(WireframeV2Fixture::document()), 100, 20, 80, 10)),
        ]);
        $payload = $this->payload();
        $payload['wireframe_ast_version'] = 2;
        $payload['site_ast'] = WireframeV2Fixture::siteAst();

        $response = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-wireframe-v2-0001')
            ->postJson('/api/v1/wireframes', $payload)
            ->assertOk()
            ->assertJsonPath('data.wireframe_ast.version', 2)
            ->assertJsonPath('data.wireframe_ast.locale', 'ja')
            ->assertJsonPath('data.wireframe_ast.pages.0.root.type', 'Region')
            ->assertJsonPath('data.wireframe_ast.pages.0.root.children.1.children.0.journey_stage', 'attention')
            ->assertJsonPath('data.wireframe_ast.pages.0.root.children.1.children.2.children.1.type', 'Region');
        $this->assertSame('無料相談を送信する', $response->json('data.wireframe_ast.pages.0.root.children.1.children.2.children.1.children.7.label'));

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $instructions = $body['instructions'] ?? '';
            $schema = $body['text']['format']['schema'] ?? [];

            return is_string($instructions)
                && str_contains($instructions, 'AIDMA')
                && str_contains($instructions, 'content-free')
                && str_contains($instructions, 'Input, Textarea, Select, or Checkbox')
                && ($schema['properties']['version']['type'] ?? null) === 'integer'
                && ($schema['properties']['version']['const'] ?? null) === 2
                && ($schema['properties']['pages']['maxItems'] ?? null) === 8
                && ($schema['$defs']['region0']['properties']['type']['type'] ?? null) === 'string'
                && ($schema['$defs']['region0']['properties']['type']['const'] ?? null) === 'Region'
                && ($schema['$defs']['region0']['properties']['semantic']['const'] ?? null) === 'document'
                && ($schema['$defs']['checkbox']['properties']['type']['type'] ?? null) === 'string'
                && ($schema['$defs']['page']['properties']['root']['$ref'] ?? null) === '#/$defs/region0'
                && ($schema['$defs']['region0']['properties']['children']['items']['$ref'] ?? null) === '#/$defs/node1'
                && ($schema['$defs']['mainRegion1']['properties']['children']['items']['$ref'] ?? null) === '#/$defs/sectionRegion2'
                && ($schema['$defs']['sectionRegion2']['properties']['children']['items']['$ref'] ?? null) === '#/$defs/node3'
                && ($schema['$defs']['region4']['properties']['children']['items']['$ref'] ?? null) === '#/$defs/node5'
                && ($schema['$defs']['listRegion4']['properties']['children']['items']['$ref'] ?? null) === '#/$defs/listItemRegion4'
                && ($schema['$defs']['listItemRegion4']['properties']['children']['items']['$ref'] ?? null) === '#/$defs/node5'
                && ($schema['$defs']['formRegion']['properties']['controls']['items']['$ref'] ?? null) === '#/$defs/formControlNode'
                && ($schema['$defs']['formRegion']['properties']['submit']['$ref'] ?? null) === '#/$defs/submitButton'
                && ($schema['$defs']['submitButton']['properties']['button_type']['const'] ?? null) === 'submit'
                && ($schema['$defs']['button']['properties']['button_type']['const'] ?? null) === 'button'
                && ! isset($schema['$defs']['node'], $schema['$defs']['region'], $schema['$defs']['listRegion'])
                && count($schema['$defs']['node1']['anyOf'] ?? []) === 3
                && count($schema['$defs']['node2']['anyOf'] ?? []) === 7
                && count($schema['$defs']['node5']['anyOf'] ?? []) === 4
                && count($schema['$defs']['formControlNode']['anyOf'] ?? []) === 4
                && ($body['max_output_tokens'] ?? null) === 64000
                && ! str_contains(json_encode($body, JSON_THROW_ON_ERROR), 'wireframe-neutral-v1');
        });
    }

    public function test_v2_retries_once_when_the_provider_returns_incomplete_json(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push([
                    'id' => 'resp_v2_incomplete',
                    'model' => 'gpt-5.6-sol-2026-08-31',
                    'status' => 'incomplete',
                    'output_text' => '{"version":2',
                    'usage' => [
                        'input_tokens' => 10,
                        'input_tokens_details' => ['cached_tokens' => 0],
                        'output_tokens' => 5,
                        'output_tokens_details' => ['reasoning_tokens' => 1],
                        'total_tokens' => 15,
                    ],
                ])
                ->push($this->providerResponse('resp_v2_complete', WireframeV2Fixture::document(), 20, 0, 40, 5)),
        ]);
        $payload = $this->payload();
        $payload['wireframe_ast_version'] = 2;
        $payload['site_ast'] = WireframeV2Fixture::siteAst();

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-wireframe-v2-incomplete-retry-0001')
            ->postJson('/api/v1/wireframes', $payload)
            ->assertOk()
            ->assertJsonPath('data.wireframe_ast.version', 2)
            ->assertJsonPath('telemetry.provider_request_count', 2)
            ->assertJsonPath('telemetry.semantic_attempt_count', 2);

        Http::assertSentCount(2);
    }

    public function test_v2_repairs_an_unambiguous_local_section_anchor_before_validation(): void
    {
        $document = WireframeV2Fixture::document();
        $document['pages'][0]['root']['children'][1]['children'][2]['id'] = 'consultation-section';
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response(
                $this->providerResponse('resp_v2_anchor', $this->providerDocument($document), 100, 0, 80, 10),
            ),
        ]);
        $payload = $this->payload();
        $payload['wireframe_ast_version'] = 2;
        $payload['site_ast'] = WireframeV2Fixture::siteAst();

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-wireframe-v2-anchor-repair-0001')
            ->postJson('/api/v1/wireframes', $payload)
            ->assertOk()
            ->assertJsonPath('data.wireframe_ast.pages.0.root.children.0.children.1.children.1.href', '#consultation-section')
            ->assertJsonPath('data.wireframe_ast.pages.0.root.children.1.children.0.children.0.children.3.href', '#consultation-section');

        Http::assertSentCount(1);
    }

    public function test_v2_rejects_invalid_site_inputs_before_incurring_a_provider_call(): void
    {
        Http::preventStrayRequests();
        $payload = $this->payload();
        $payload['wireframe_ast_version'] = 2;
        $payload['site_ast']['pages'][0]['path'] = '/Privacy/';

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'openai-wireframe-v2-invalid-input-0001')
            ->postJson('/api/v1/wireframes', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');

        Http::assertNothingSent();
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

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function providerDocument(array $document): array
    {
        $convert = function (array $node) use (&$convert): array {
            if (($node['type'] ?? null) !== 'Region') {
                return $node;
            }
            $children = array_map($convert, $node['children'] ?? []);
            if (($node['semantic'] ?? null) !== 'form') {
                $node['children'] = $children;

                return $node;
            }
            $node['content'] = array_values(array_filter($children, fn (array $child): bool => ! in_array($child['type'] ?? null, ['Input', 'Textarea', 'Select', 'Checkbox'], true) && ($child['button_type'] ?? null) !== 'submit'));
            $node['controls'] = array_values(array_filter($children, fn (array $child): bool => in_array($child['type'] ?? null, ['Input', 'Textarea', 'Select', 'Checkbox'], true)));
            $node['submit'] = array_values(array_filter($children, fn (array $child): bool => ($child['type'] ?? null) === 'Button' && ($child['button_type'] ?? null) === 'submit'))[0];
            unset($node['children']);

            return $node;
        };
        foreach ($document['pages'] as $index => $page) {
            $document['pages'][$index]['root'] = $convert($page['root']);
        }

        return $document;
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
