<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\WireframeV2Fixture;
use Tests\TestCase;

final class WireframePresentationControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wireframe.service_token', 'test-token');
        config()->set('wireframe.layout_snapshots.disk', 'layout-snapshots-test');
    }

    public function test_candidate_snapshot_renders_for_browser_validation_without_changing_body_or_layout_css(): void
    {
        Http::preventStrayRequests();
        Storage::fake('layout-snapshots-test');
        $snapshotResponse = $this->createSnapshot();
        $reference = $snapshotResponse->json('layout_snapshot_references.0');
        $sourceHtml = base64_decode($snapshotResponse->json('layout_snapshot.pages.0.source_html.content_base64'), true);
        $layoutCss = $snapshotResponse->json('layout_snapshot.pages.0.layout.css');

        $response = $this->withToken('test-token')->postJson('/api/v1/wireframe-presentations', [
            'contract_version' => '1.0',
            'layout_snapshot_ref' => $reference,
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('contract_version', '1.0')
            ->assertJsonPath('entry_path', 'page-home.html')
            ->assertJsonPath('layout_snapshot_reference', $reference)
            ->assertJsonPath('presentation_snapshot.profile', 'wireframe-presentation-v1')
            ->assertJsonPath('presentation_snapshot.layout_snapshot_id', $reference['snapshot_id'])
            ->assertJsonPath('presentation_snapshot.source_snapshot_status', 'candidate')
            ->assertJsonPath('presentation_snapshot.source_validation_status', 'pending')
            ->assertJsonPath('wireframe_skin_ast.preset', 'wireframe-neutral-skin-v2')
            ->assertJsonPath('wireframe_decor_ast.preset', 'wireframe-structure-decor-v2')
            ->assertJsonPath('wireframe_decor_ast.geometry_mutation', false)
            ->assertJsonPath('telemetry.operation', 'wireframes.present')
            ->assertJsonPath('telemetry.provider', 'deterministic')
            ->assertJsonPath('telemetry.model', null)
            ->assertJsonPath('telemetry.provider_request_count', 0);

        $previewHtml = base64_decode($response->json('files.0.content_base64'), true);
        $this->assertIsString($sourceHtml);
        $this->assertIsString($previewHtml);
        $this->assertStringContainsString($layoutCss, $previewHtml);
        $this->assertSame($this->body($sourceHtml), $this->body($previewHtml));
        $this->assertStringNotContainsString('<style', $sourceHtml);
        $this->assertStringContainsString('data-layout-snapshot="'.$reference['snapshot_id'].'"', $previewHtml);
        $this->assertStringContainsString('<meta name="layout-source-html-sha256" content="'.$reference['source_html_digest'].'">', $previewHtml);
        $this->assertStringContainsString('<meta name="layout-ast-sha256" content="'.$reference['layout_ast_digest'].'">', $previewHtml);
        $this->assertStringContainsString('<meta name="layout-css-sha256" content="'.$reference['layout_css_digest'].'">', $previewHtml);
        $this->assertStringContainsString('data-wireframe-presentation="wireframe-presentation-v1"', $previewHtml);
        preg_match('/<style data-wireframe-presentation="wireframe-presentation-v1">\s*(.*?)\s*<\/style>/s', $previewHtml, $match);
        $this->assertArrayHasKey(1, $match);
        $this->assertDoesNotMatchRegularExpression('/(?:^|[;{])\s*(?:font|border|padding|margin|display|width|height|gap|position|inset|transform|animation|transition)(?:-[a-z]+)?\s*:/i', $match[1]);
        $this->assertSame(hash('sha256', $previewHtml), $response->json('file_manifest.0.sha256'));
        Http::assertNothingSent();
    }

    public function test_frozen_snapshot_uses_the_same_presenter_and_reports_its_approved_status(): void
    {
        Http::preventStrayRequests();
        Storage::fake('layout-snapshots-test');
        $candidateResponse = $this->createSnapshot();
        $candidate = $candidateResponse->json('layout_snapshot');
        $frozenResponse = $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/freeze',
            [
                'contract_version' => '1.0',
                'candidate_snapshot_digest' => $candidate['snapshot_digest'],
                'validation' => $this->passedValidation($candidate),
            ],
        )->assertCreated();
        $reference = $frozenResponse->json('layout_snapshot_references.0');

        $this->withToken('test-token')->postJson('/api/v1/wireframe-presentations', [
            'contract_version' => '1.0',
            'layout_snapshot_ref' => $reference,
        ])->assertOk()
            ->assertJsonPath('presentation_snapshot.source_snapshot_status', 'frozen')
            ->assertJsonPath('presentation_snapshot.source_validation_status', 'passed')
            ->assertJsonPath('layout_snapshot_reference', $reference);
        Http::assertNothingSent();
    }

    public function test_two_finite_patch_rounds_remain_presentable_with_preview_validation_provenance(): void
    {
        Http::preventStrayRequests();
        Storage::fake('layout-snapshots-test');
        $initialResponse = $this->createSnapshot();
        $candidate = $initialResponse->json('layout_snapshot');
        $sourceHtml = base64_decode((string) $candidate['pages'][0]['source_html']['content_base64'], true);
        $sourceHtmlDigest = (string) $candidate['pages'][0]['source_html']['sha256'];
        $initialLayoutAstDigest = (string) $candidate['pages'][0]['layout']['ast_digest'];

        $this->assertIsString($sourceHtml);
        $this->assertSame(hash('sha256', $sourceHtml), $sourceHtmlDigest);
        $this->assertSame($initialLayoutAstDigest, $this->meta($sourceHtml, 'layout-ast-sha256'));
        $previewValidatedAstDigest = $initialLayoutAstDigest;

        foreach (['cover', 'contain'] as $objectFit) {
            $this->assertSame($candidate['pages'][0]['layout']['ast_digest'], $previewValidatedAstDigest);
            $patchResponse = $this->withToken('test-token')->postJson(
                '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
                [
                    'contract_version' => '1.0',
                    'page_key' => 'home',
                    'patch_plan' => [
                        'contract_version' => '1.0',
                        'stage' => 'layout',
                        'base_ast_digest' => $previewValidatedAstDigest,
                        'issues' => [[
                            'target_id' => 'layout.image',
                            'viewport' => ['min_width' => 1024],
                            'problem' => 'object_fit_mismatch',
                            'operation' => ['type' => 'set_object_fit', 'value' => $objectFit],
                        ]],
                    ],
                ],
            )->assertCreated();

            $patched = $patchResponse->json('layout_snapshot');
            $reference = $patchResponse->json('layout_snapshot_references.0');
            $this->assertNotSame($candidate['snapshot_id'], $patched['snapshot_id']);
            $this->assertSame($sourceHtmlDigest, $reference['source_html_digest']);
            $this->assertSame($candidate['pages'][0]['source_html'], $patched['pages'][0]['source_html']);

            $presentation = $this->withToken('test-token')->postJson('/api/v1/wireframe-presentations', [
                'contract_version' => '1.0',
                'layout_snapshot_ref' => $reference,
            ])->assertOk();
            $previewHtml = base64_decode((string) $presentation->json('files.0.content_base64'), true);

            $this->assertIsString($previewHtml);
            $this->assertSame($this->body($sourceHtml), $this->body($previewHtml));
            $this->assertSame($reference['snapshot_id'], $this->meta($previewHtml, 'layout-snapshot-id'));
            $this->assertSame($reference['snapshot_digest'], $this->meta($previewHtml, 'layout-snapshot-sha256'));
            $this->assertSame($reference['source_html_digest'], $this->meta($previewHtml, 'layout-source-html-sha256'));
            $this->assertSame($reference['layout_ast_digest'], $this->meta($previewHtml, 'layout-ast-sha256'));
            $this->assertSame($reference['layout_css_digest'], $this->meta($previewHtml, 'layout-css-sha256'));
            $this->assertSame('wireframe-presentation-v1', $this->meta($previewHtml, 'wireframe-presentation-profile'));
            $this->assertNotSame($initialLayoutAstDigest, $this->meta($previewHtml, 'layout-ast-sha256'));

            // A Preview validation is read-only. Its provenance subject becomes the
            // next review's base, so the following patch must bind this exact AST.
            $previewValidatedAstDigest = $this->meta($previewHtml, 'layout-ast-sha256');
            $candidate = $patched;
        }

        $this->assertSame($initialLayoutAstDigest, $this->meta($sourceHtml, 'layout-ast-sha256'));
        $this->assertSame($sourceHtmlDigest, hash('sha256', $sourceHtml));
        $this->assertCount(3, Storage::disk('layout-snapshots-test')->allFiles('layout-snapshots'));
        Http::assertNothingSent();
    }

    public function test_returns_422_when_any_layout_snapshot_reference_digest_is_changed(): void
    {
        Storage::fake('layout-snapshots-test');
        $snapshotResponse = $this->createSnapshot();
        $reference = $snapshotResponse->json('layout_snapshot_references.0');
        $reference['layout_css_digest'] = str_repeat('a', 64);

        $this->withToken('test-token')->postJson('/api/v1/wireframe-presentations', [
            'contract_version' => '1.0',
            'layout_snapshot_ref' => $reference,
        ])->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_layout_snapshot_reference');
    }

    public function test_returns_404_when_the_layout_snapshot_reference_has_expired_or_does_not_exist(): void
    {
        Storage::fake('layout-snapshots-test');
        $digest = str_repeat('a', 64);

        $this->withToken('test-token')->postJson('/api/v1/wireframe-presentations', [
            'contract_version' => '1.0',
            'layout_snapshot_ref' => [
                'profile' => 'layout-snapshot-reference-v1',
                'snapshot_id' => 'ls_'.$digest,
                'snapshot_digest' => $digest,
                'page_key' => 'home',
                'source_html_digest' => $digest,
                'layout_ast_digest' => $digest,
                'layout_css_digest' => $digest,
            ],
        ])->assertNotFound()
            ->assertJsonPath('type', 'layout_snapshot_not_found');
    }

    public function test_presentation_route_requires_service_authentication(): void
    {
        $digest = str_repeat('a', 64);

        $this->postJson('/api/v1/wireframe-presentations', [
            'contract_version' => '1.0',
            'layout_snapshot_ref' => [
                'profile' => 'layout-snapshot-reference-v1',
                'snapshot_id' => 'ls_'.$digest,
                'snapshot_digest' => $digest,
                'page_key' => 'home',
                'source_html_digest' => $digest,
                'layout_ast_digest' => $digest,
                'layout_css_digest' => $digest,
            ],
        ])->assertUnauthorized();
    }

    private function createSnapshot(): TestResponse
    {
        return $this->withToken('test-token')->postJson('/api/v1/layout-snapshots', [
            'contract_version' => '1.0',
            'wireframe_ast' => WireframeV2Fixture::document(),
            'validation_viewports' => [390, 768, 1440],
        ])->assertCreated();
    }

    private function body(string $html): string
    {
        preg_match('/<body(?:\s[^>]*)?>.*<\/body>/is', $html, $match);

        return $match[0];
    }

    private function meta(string $html, string $name): string
    {
        preg_match_all(
            '/<meta name="'.preg_quote($name, '/').'" content="([^"]*)">/',
            $html,
            $matches,
        );
        $this->assertCount(1, $matches[1], "Expected exactly one {$name} meta declaration.");

        return $matches[1][0];
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
}
