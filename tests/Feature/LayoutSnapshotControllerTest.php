<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WireframeV2Fixture;
use Tests\TestCase;

final class LayoutSnapshotControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wireframe.service_token', 'test-token');
        config()->set('wireframe.layout_service_token', 'layout-test-token');
        config()->set('wireframe.layout_snapshots.disk', 'layout-snapshots-test');
    }

    public function test_valid_wireframe_creates_an_encrypted_content_addressed_candidate_and_replays_without_overwriting(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::fake('layout-snapshots-test');
        $this->travelTo('2026-09-02 12:00:00');

        $first = $this->withToken('test-token')->postJson('/api/v1/layout-snapshots', $this->payload())
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('contract_version', '1.0')
            ->assertJsonPath('layout_snapshot.profile', 'layout-snapshot-v1')
            ->assertJsonPath('layout_snapshot.status', 'candidate')
            ->assertJsonPath('layout_snapshot.validation.status', 'pending')
            ->assertJsonPath('layout_snapshot.validation.subject', null)
            ->assertJsonPath('layout_snapshot.validation.pages.0.viewports.0.width_px', 390)
            ->assertJsonPath('layout_snapshot.validation.pages.0.viewports.0.breakpoint', 'compact')
            ->assertJsonPath('layout_snapshot.validation.pages.0.viewports.0.geometry_outcome', 'pending')
            ->assertJsonPath('layout_snapshot.layout_ast_version', 2)
            ->assertJsonPath('layout_snapshot.constraints.container_max_width_px', 1120)
            ->assertJsonPath('layout_snapshot.constraints.content_max_width_px', 760)
            ->assertJsonPath('layout_snapshot.constraints.validation_viewports_px', [390, 768, 1440])
            ->assertJsonPath('layout_snapshot.pages.0.route', '/')
            ->assertJsonPath('layout_snapshot.pages.0.layout.profile', 'geometry-layout-v2')
            ->assertJsonPath('layout_snapshot.pages.0.layout.ast.version', 2)
            ->assertJsonPath('layout_snapshot.pages.0.layout.compiler', 'yutoseta/magic-html-layout-ast')
            ->assertJsonPath('layout_snapshot.pages.0.layout.compiler_version', '2.0')
            ->assertJsonPath('layout_snapshot_references.0.profile', 'layout-snapshot-reference-v1')
            ->assertJsonPath('storage.write_once', true)
            ->assertJsonPath('storage.encrypted', true)
            ->assertJsonPath('storage.ttl_seconds', 604800);

        $snapshotId = $first->json('layout_snapshot.snapshot_id');
        $snapshotDigest = $first->json('layout_snapshot.snapshot_digest');
        $this->assertMatchesRegularExpression('/\Als_[a-f0-9]{64}\z/D', $snapshotId);
        $this->assertSame('ls_'.$snapshotDigest, $snapshotId);
        $this->assertSame($snapshotId, $first->json('layout_snapshot_references.0.snapshot_id'));
        $this->assertSame($snapshotDigest, $first->json('layout_snapshot_references.0.snapshot_digest'));
        $sourceHtml = base64_decode($first->json('layout_snapshot.pages.0.source_html.content_base64'), true);
        $this->assertIsString($sourceHtml);
        $this->assertSame(hash('sha256', $sourceHtml), $first->json('layout_snapshot.pages.0.source_html.sha256'));
        $this->assertSame(
            $first->json('layout_snapshot.pages.0.source_html.sha256'),
            $first->json('layout_snapshot_references.0.source_html_digest'),
        );
        $layoutCss = $first->json('layout_snapshot.pages.0.layout.css');
        $this->assertIsString($layoutCss);
        $this->assertStringNotContainsString('--mh-tone-', $layoutCss);
        $this->assertDoesNotMatchRegularExpression('/(?:^|[;{])\s*(?:background|color|border|outline|animation|transition)(?:-[a-z]+)?\s*:/i', $layoutCss);
        $layoutRules = $first->json('layout_snapshot.pages.0.layout.ast.rules');
        $this->assertContains('cluster', array_map(
            static fn (array $rule): mixed => $rule['style']['layout']['flow'] ?? null,
            $layoutRules,
        ));
        $layoutRulesById = collect($layoutRules)->keyBy('id');
        $this->assertSame('split', $layoutRulesById->get('layout.region.hero')['style']['layout']['flow']);
        $this->assertSame('3fr 2fr', $layoutRulesById->get('layout.region.hero')['style']['layout']['template']);
        $this->assertSame('grid', $layoutRulesById->get('layout.region.symptoms')['style']['layout']['flow']);
        $this->assertSame('1fr 1fr', $layoutRulesById->get('layout.region.symptoms')['style']['layout']['template']);
        $this->assertMatchesRegularExpression(
            '/\/\* layout\.region\.hero \*\/.*?display: grid;.*?grid-template-columns: 3fr 2fr;/s',
            $layoutCss,
        );
        $this->assertMatchesRegularExpression(
            '/\/\* layout\.region\.symptoms \*\/.*?display: grid;.*?grid-template-columns: 1fr 1fr;/s',
            $layoutCss,
        );
        foreach (array_column($layoutRules, 'id') as $ruleId) {
            $this->assertStringStartsWith('layout.', $ruleId);
        }
        $this->assertCount(1, $disk->allFiles('layout-snapshots'));
        $encrypted = $disk->get($disk->allFiles('layout-snapshots')[0]);
        $this->assertStringNotContainsString('ウェブ修理工房', $encrypted);
        $this->assertStringContainsString('"snapshot_id":"'.$snapshotId.'"', Crypt::decryptString($encrypted));

        $second = $this->withToken('test-token')->postJson('/api/v1/layout-snapshots', $this->payload())
            ->assertOk()
            ->assertJsonPath('layout_snapshot.snapshot_id', $snapshotId)
            ->assertJsonPath('storage.created_at', '2026-09-02T12:00:00+00:00');
        $this->assertSame($first->json('layout_snapshot'), $second->json('layout_snapshot'));
        $this->assertCount(1, $disk->allFiles('layout-snapshots'));
        Http::assertNothingSent();
    }

    public function test_browser_attestation_freezes_a_new_snapshot_without_changing_source_or_layout(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::fake('layout-snapshots-test');
        $candidateResponse = $this->withToken('test-token')
            ->postJson('/api/v1/layout-snapshots', $this->payload())
            ->assertCreated();
        $candidate = $candidateResponse->json('layout_snapshot');

        $frozenResponse = $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/freeze',
            [
                'contract_version' => '1.0',
                'candidate_snapshot_digest' => $candidate['snapshot_digest'],
                'validation' => $this->passedValidation($candidate),
            ],
        )->assertCreated()
            ->assertJsonPath('layout_snapshot.status', 'frozen')
            ->assertJsonPath('layout_snapshot.validation.status', 'passed')
            ->assertJsonPath('layout_snapshot.validation.validator', 'magic-html-preview-service')
            ->assertJsonPath('layout_snapshot.lineage.parent_snapshot_id', $candidate['snapshot_id'])
            ->assertJsonPath('layout_snapshot.lineage.parent_snapshot_digest', $candidate['snapshot_digest'])
            ->assertJsonPath('layout_snapshot.lineage.delta.operations.0.operation', 'attach_validation')
            ->assertJsonPath('layout_snapshot.lineage.delta.operations.0.target.kind', 'snapshot');

        $frozen = $frozenResponse->json('layout_snapshot');
        $this->assertNotSame($candidate['snapshot_id'], $frozen['snapshot_id']);
        $this->assertSame($this->pageArtifacts($candidate), $this->pageArtifacts($frozen));
        $this->assertSame('candidate', $candidate['status']);
        $this->assertSame('pending', $candidate['validation']['status']);
        $this->assertCount(2, $disk->allFiles('layout-snapshots'));

        $replay = $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/freeze',
            [
                'contract_version' => '1.0',
                'candidate_snapshot_digest' => $candidate['snapshot_digest'],
                'validation' => $this->passedValidation($candidate),
            ],
        )->assertOk();
        $this->assertSame($frozen['snapshot_id'], $replay->json('layout_snapshot.snapshot_id'));
        $this->assertCount(2, $disk->allFiles('layout-snapshots'));
        Http::assertNothingSent();
    }

    public function test_stored_snapshot_can_be_fetched_by_id_until_its_explicit_ttl_expires(): void
    {
        Storage::fake('layout-snapshots-test');
        config()->set('wireframe.layout_snapshots.ttl_seconds', 600);
        $this->travelTo('2026-09-02 12:00:00');
        $created = $this->withToken('test-token')->postJson('/api/v1/layout-snapshots', $this->payload())->assertCreated();
        $snapshotId = $created->json('layout_snapshot.snapshot_id');

        $this->withToken('test-token')->getJson('/api/v1/layout-snapshots/'.$snapshotId)
            ->assertOk()
            ->assertHeader('Cache-Control', 'immutable, private')
            ->assertJsonPath('layout_snapshot.snapshot_id', $snapshotId);

        $this->travel(601)->seconds();
        $this->withToken('test-token')->getJson('/api/v1/layout-snapshots/'.$snapshotId)
            ->assertNotFound()
            ->assertJsonPath('type', 'layout_snapshot_not_found');
        $this->assertSame([], Storage::disk('layout-snapshots-test')->allFiles('layout-snapshots'));
    }

    public function test_freeze_rejects_an_attestation_for_a_different_candidate_or_incomplete_page_set(): void
    {
        Storage::fake('layout-snapshots-test');
        $candidate = $this->withToken('test-token')
            ->postJson('/api/v1/layout-snapshots', $this->payload())
            ->assertCreated()
            ->json('layout_snapshot');

        $wrongSubject = $this->passedValidation($candidate);
        $wrongSubject['subject']['candidate_snapshot_digest'] = str_repeat('a', 64);
        $wrongSubject['subject']['candidate_snapshot_id'] = 'ls_'.str_repeat('a', 64);
        $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/freeze',
            [
                'contract_version' => '1.0',
                'candidate_snapshot_digest' => $candidate['snapshot_digest'],
                'validation' => $wrongSubject,
            ],
        )->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_layout_snapshot_attestation');

        $incomplete = $this->passedValidation($candidate);
        array_pop($incomplete['pages']);
        $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/freeze',
            [
                'contract_version' => '1.0',
                'candidate_snapshot_digest' => $candidate['snapshot_digest'],
                'validation' => $incomplete,
            ],
        )->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_layout_snapshot_attestation');
        $this->assertCount(1, Storage::disk('layout-snapshots-test')->allFiles('layout-snapshots'));
    }

    public function test_returns_422_when_validation_viewports_do_not_cover_compact_medium_and_wide(): void
    {
        Storage::fake('layout-snapshots-test');
        $payload = $this->payload();
        $payload['validation_viewports'] = [390, 768, 1024];

        $this->withToken('test-token')->postJson('/api/v1/layout-snapshots', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('validation_viewports');
        $this->assertSame([], Storage::disk('layout-snapshots-test')->allFiles());
    }

    public function test_snapshot_routes_require_service_authentication(): void
    {
        Storage::fake('layout-snapshots-test');

        $this->postJson('/api/v1/layout-snapshots', $this->payload())->assertUnauthorized();
        $this->getJson('/api/v1/layout-snapshots/ls_'.str_repeat('a', 64))->assertUnauthorized();
        $this->postJson('/api/v1/layout-snapshots/ls_'.str_repeat('a', 64).'/freeze', [])->assertUnauthorized();
    }

    public function test_layout_service_token_is_limited_to_layout_routes(): void
    {
        Storage::fake('layout-snapshots-test');

        $this->withToken('layout-test-token')
            ->postJson('/api/v1/layout-snapshots', $this->payload())
            ->assertCreated();

        $this->withToken('layout-test-token')
            ->postJson('/api/v1/wireframes/materialize', [])
            ->assertUnauthorized();
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'contract_version' => '1.0',
            'wireframe_ast' => WireframeV2Fixture::document(),
            'validation_viewports' => [390, 768, 1440],
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function passedValidation(array $candidate): array
    {
        return [
            'status' => 'passed',
            'validator' => 'magic-html-preview-service',
            'validator_version' => '1.0',
            'subject' => [
                'candidate_snapshot_id' => $candidate['snapshot_id'],
                'candidate_snapshot_digest' => $candidate['snapshot_digest'],
            ],
            'pages' => array_map(static fn (array $page): array => [
                'page_key' => $page['page_key'],
                'route' => $page['route'],
                'path' => $page['path'],
                'viewports' => self::passedViewports(),
            ], $candidate['pages']),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function passedViewports(): array
    {
        return array_map(static fn (int $width, int $height, string $breakpoint): array => [
            'width_px' => $width,
            'height_px' => $height,
            'breakpoint' => $breakpoint,
            'breakpoint_outcome' => 'passed',
            'geometry_outcome' => 'passed',
            'measurements' => [
                'container_width_px' => min($width, 1120),
                'content_width_px' => min($width, 760),
                'horizontal_overflow_px' => 0,
                'minimum_action_height_px' => 44,
                'overlap_count' => 0,
                'clipped_content_count' => 0,
                'zero_dimension_count' => 0,
                'responsive_violation_count' => 0,
                'image_aspect_violation_count' => 0,
                'checks' => [
                    'container_width_within_limit' => true,
                    'content_width_within_limit' => true,
                    'no_horizontal_overflow' => true,
                    'minimum_action_height_met' => true,
                    'source_order_preserved' => true,
                    'no_overlap' => true,
                    'no_clipped_content' => true,
                    'no_zero_dimensions' => true,
                    'responsive_behavior_matches' => true,
                    'image_aspect_preserved' => true,
                ],
            ],
            'issues' => [],
        ], [390, 768, 1440], [844, 1024, 900], ['compact', 'medium', 'wide']);
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,string>> */
    private function pageArtifacts(array $snapshot): array
    {
        return array_map(static fn (array $page): array => [
            'source_html' => $page['source_html']['sha256'],
            'layout_ast' => $page['layout']['ast_digest'],
            'layout_css' => $page['layout']['css_digest'],
        ], $snapshot['pages']);
    }
}
