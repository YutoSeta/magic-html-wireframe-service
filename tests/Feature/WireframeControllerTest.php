<?php

namespace Tests\Feature;

use App\Exceptions\InvalidWireframeException;
use App\Services\Contracts\WireframeGenerator;
use App\Services\WireframeValidator;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class WireframeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wireframe.service_token', 'test-token');
    }

    public function test_site_ast_is_converted_through_the_generator_boundary(): void
    {
        $this->bindGenerator();

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'wireframe-generation-0001')
            ->postJson('/api/v1/wireframes', $this->payload())
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('wireframe_ast.pages.0.key', 'home')
            ->assertJsonPath('wireframe_ast.pages.0.sections.0.composition', 'hero');
    }

    public function test_authentication_and_json_object_shape_are_enforced(): void
    {
        $this->postJson('/api/v1/wireframes', $this->payload())->assertUnauthorized();
        $payload = $this->payload();
        $payload['site_ast'] = [];
        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'wireframe-validation-0001')
            ->postJson('/api/v1/wireframes', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('site_ast');
    }

    public function test_exact_retry_replays_the_immutable_response_without_regenerating(): void
    {
        $generator = $this->bindGenerator(function (array $siteAst, int $call): array {
            $wireframe = $this->wireframe($siteAst);
            $wireframe['pages'][0]['sections'][0]['key'] = "hero-{$call}";

            return $wireframe;
        });

        $first = $this->withToken('test-token')
            ->withHeaders(['Idempotency-Key' => 'wireframe-replay-0001', 'X-Request-Id' => 'first-request'])
            ->postJson('/api/v1/wireframes', $this->payload());
        $second = $this->withToken('test-token')
            ->withHeaders(['Idempotency-Key' => 'wireframe-replay-0001', 'X-Request-Id' => 'retry-request'])
            ->postJson('/api/v1/wireframes', $this->payload());

        $first->assertOk()->assertHeader('Idempotent-Replayed', 'false');
        $second->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $generator->calls);
    }

    public function test_legacy_raw_wireframe_records_remain_replayable_without_telemetry(): void
    {
        $generator = $this->bindGenerator();
        $idempotencyKey = 'wireframe-legacy-replay-0001';
        $payload = $this->payload();
        $requestBytes = json_encode($payload, JSON_THROW_ON_ERROR);
        $keyHash = hash('sha256', $idempotencyKey);
        Cache::forever("wireframe-idempotency:{$keyHash}", [
            'version' => 1,
            'operation' => 'wireframes.generate',
            'request_hash' => hash('sha256', "wireframes\0{$payload['contract_version']}\0{$requestBytes}"),
            'state' => 'completed',
            'response' => $this->wireframe($payload['site_ast']),
        ]);
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer test-token',
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        $response = $this->call('POST', '/api/v1/wireframes', [], [], [], $server, $requestBytes);

        $response->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonMissingPath('telemetry')
            ->assertJsonPath('wireframe_ast.pages.0.key', 'home');
        $this->assertSame(0, $generator->calls);
    }

    public function test_database_store_replays_the_response_across_requests(): void
    {
        config()->set('wireframe.idempotency.store', 'database');
        $generator = $this->bindGenerator();

        $first = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'wireframe-database-replay-0001')
            ->postJson('/api/v1/wireframes', $this->payload());
        $second = $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'wireframe-database-replay-0001')
            ->postJson('/api/v1/wireframes', $this->payload());

        $first->assertOk()->assertHeader('Idempotent-Replayed', 'false');
        $second->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $generator->calls);
    }

    public function test_returns_409_when_the_same_key_is_reused_with_a_different_payload(): void
    {
        $generator = $this->bindGenerator();

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'wireframe-conflict-0001')
            ->postJson('/api/v1/wireframes', $this->payload())
            ->assertOk();
        $changed = $this->payload();
        $changed['brief']['goals'] = '採用応募の増加';

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'wireframe-conflict-0001')
            ->postJson('/api/v1/wireframes', $changed)
            ->assertConflict()
            ->assertJsonPath('type', 'idempotency_conflict');
        $this->assertSame(1, $generator->calls);
    }

    public function test_returns_409_when_semantically_equal_json_uses_different_request_bytes(): void
    {
        $generator = $this->bindGenerator();
        $payload = $this->payload();
        $reordered = [
            'locale' => $payload['locale'],
            'brief' => $payload['brief'],
            'site_ast' => $payload['site_ast'],
            'contract_version' => $payload['contract_version'],
        ];
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer test-token',
            'HTTP_IDEMPOTENCY_KEY' => 'wireframe-exact-bytes-0001',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        $first = $this->call('POST', '/api/v1/wireframes', [], [], [], $server, json_encode($payload, JSON_THROW_ON_ERROR));
        $second = $this->call('POST', '/api/v1/wireframes', [], [], [], $server, json_encode($reordered, JSON_THROW_ON_ERROR));

        $first->assertOk();
        $second->assertConflict()->assertJsonPath('type', 'idempotency_conflict');
        $this->assertSame(1, $generator->calls);
    }

    public function test_returns_409_without_generating_when_the_key_is_already_processing(): void
    {
        $generator = $this->bindGenerator();
        $idempotencyKey = 'wireframe-in-progress-0001';
        $keyHash = hash('sha256', $idempotencyKey);
        $lock = Cache::lock("wireframe-idempotency-lock:{$keyHash}", 10);
        $this->assertTrue($lock->get());

        try {
            $this->withToken('test-token')
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->postJson('/api/v1/wireframes', $this->payload())
                ->assertConflict()
                ->assertJsonPath('type', 'idempotency_in_progress');
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $generator->calls);
    }

    public function test_provider_failure_releases_the_unfinished_claim_for_a_safe_retry(): void
    {
        $generator = $this->bindGenerator(function (array $siteAst, int $call): array {
            if ($call === 1) {
                throw new RuntimeException('Provider unavailable.');
            }

            return $this->wireframe($siteAst);
        });

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'wireframe-provider-retry-0001')
            ->postJson('/api/v1/wireframes', $this->payload())
            ->assertStatus(502);
        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', 'wireframe-provider-retry-0001')
            ->postJson('/api/v1/wireframes', $this->payload())
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'false');

        $this->assertSame(2, $generator->calls);
    }

    #[DataProvider('invalidIdempotencyKeys')]
    public function test_returns_422_for_a_missing_or_out_of_bounds_idempotency_key(?string $idempotencyKey): void
    {
        $generator = $this->bindGenerator();
        $request = $this->withToken('test-token');
        if ($idempotencyKey !== null) {
            $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        $request->postJson('/api/v1/wireframes', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Idempotency-Key');
        $this->assertSame(0, $generator->calls);
    }

    /** @return array<string,array{string|null}> */
    public static function invalidIdempotencyKeys(): array
    {
        return [
            'missing' => [null],
            'seven characters' => ['1234567'],
            '201 characters' => [str_repeat('a', 201)],
        ];
    }

    #[DataProvider('validIdempotencyKeyBoundaries')]
    public function test_accepts_idempotency_keys_at_the_contract_boundaries(string $idempotencyKey): void
    {
        $this->bindGenerator();

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/wireframes', $this->payload())
            ->assertOk();
    }

    /** @return array<string,array{string}> */
    public static function validIdempotencyKeyBoundaries(): array
    {
        return [
            'eight characters' => ['12345678'],
            '200 characters' => [str_repeat('a', 200)],
        ];
    }

    public function test_persisted_replay_state_contains_only_the_request_hash_and_safe_response(): void
    {
        $this->bindGenerator();
        $idempotencyKey = 'wireframe-redacted-state-0001';

        $this->withToken('test-token')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/wireframes', $this->payload())
            ->assertOk();

        $record = Cache::get('wireframe-idempotency:'.hash('sha256', $idempotencyKey));
        $this->assertIsArray($record);
        $this->assertSame(['version', 'operation', 'request_hash', 'state', 'response'], array_keys($record));
        $serialized = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Web工房', $serialized);
        $this->assertStringNotContainsString('問い合わせ増加', $serialized);
        $this->assertStringNotContainsString($idempotencyKey, $serialized);
    }

    public function test_validator_rejects_page_mismatch_and_positional_design_shortcuts(): void
    {
        $this->expectException(InvalidWireframeException::class);
        app(WireframeValidator::class)->validate([
            'pages' => [[
                'key' => 'other',
                'sections' => [
                    ['key' => 'first', 'composition' => 'hero', 'roles' => ['Title']],
                    ['key' => 'second', 'composition' => 'cta', 'roles' => ['Actions']],
                ],
            ]],
        ], $this->payload()['site_ast']);
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

    private function bindGenerator(?Closure $callback = null): RecordingWireframeGenerator
    {
        $generator = new RecordingWireframeGenerator(
            $callback ?? fn (array $siteAst): array => $this->wireframe($siteAst),
        );
        $this->app->instance(WireframeGenerator::class, $generator);

        return $generator;
    }

    /** @param array<string,mixed> $siteAst @return array<string,mixed> */
    private function wireframe(array $siteAst): array
    {
        return [
            'version' => 1,
            'pages' => [[
                'key' => $siteAst['pages'][0]['key'],
                'sections' => [
                    ['key' => 'hero', 'composition' => 'hero', 'roles' => ['Title', 'Text', 'Actions', 'Image']],
                    ['key' => 'cta', 'composition' => 'cta', 'roles' => ['Title', 'Text', 'Actions']],
                ],
            ]],
        ];
    }
}

final class RecordingWireframeGenerator implements WireframeGenerator
{
    public int $calls = 0;

    public function __construct(private readonly Closure $callback) {}

    public function generate(array $siteAst, array $brief, string $locale): array
    {
        $this->calls++;

        return ($this->callback)($siteAst, $this->calls, $brief, $locale);
    }
}
