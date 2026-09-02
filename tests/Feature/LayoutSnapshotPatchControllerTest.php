<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WireframeV2Fixture;
use Tests\TestCase;
use YutoSeta\MagicHtmlLayout\LayoutSnapshot;

final class LayoutSnapshotPatchControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wireframe.service_token', 'test-token');
        config()->set('wireframe.layout_snapshots.disk', 'layout-snapshots-test');
    }

    public function test_valid_finite_rule_patch_creates_a_new_pending_candidate_and_preserves_semantic_html(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::fake('layout-snapshots-test');
        $candidate = $this->candidate();
        $patchPlan = $this->patchPlan($candidate, [[
            'target_id' => 'layout.image',
            'viewport' => ['min_width' => 1024],
            'problem' => 'object_fit_mismatch',
            'operation' => ['type' => 'set_object_fit', 'value' => 'cover'],
        ]]);

        $first = $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $patchPlan],
        )->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('contract_version', '1.0')
            ->assertJsonPath('layout_snapshot.status', 'candidate')
            ->assertJsonPath('layout_snapshot.validation.status', 'pending')
            ->assertJsonPath('layout_snapshot.lineage.parent_snapshot_id', $candidate['snapshot_id'])
            ->assertJsonPath('layout_snapshot.lineage.parent_snapshot_digest', $candidate['snapshot_digest'])
            ->assertJsonPath('layout_snapshot.lineage.delta.operations.0.operation', 'apply_finite_layout_patch')
            ->assertJsonPath('layout_snapshot.lineage.delta.operations.0.target.kind', 'page')
            ->assertJsonPath('layout_snapshot.lineage.delta.operations.0.target.page_key', 'home');

        $patched = $first->json('layout_snapshot');
        $this->assertSame(
            LayoutSnapshot::canonicalJson($patchPlan),
            LayoutSnapshot::canonicalJson($patched['lineage']['delta']['patch_plan']),
        );
        $this->assertSame(
            LayoutSnapshot::canonicalJson($patchPlan['issues'][0]),
            LayoutSnapshot::canonicalJson($patched['lineage']['delta']['operations'][0]['finite_patch']),
        );
        $this->assertNotSame($candidate['snapshot_id'], $patched['snapshot_id']);
        $this->assertSame($this->semanticArtifacts($candidate), $this->semanticArtifacts($patched));
        $this->assertNotSame(
            $candidate['pages'][0]['layout']['ast_digest'],
            $patched['pages'][0]['layout']['ast_digest'],
        );
        $this->assertSame(
            array_slice($this->layoutArtifacts($candidate), 1),
            array_slice($this->layoutArtifacts($patched), 1),
        );
        $this->assertSame('cover', $this->rule($patched, 'home', 'layout.image')['responsive']['wide']['media']['fit']);
        $this->assertStringContainsString('object-fit: cover', $patched['pages'][0]['layout']['css']);
        foreach ($patched['validation']['pages'] as $pageValidation) {
            foreach ($pageValidation['viewports'] as $viewport) {
                $this->assertSame('pending', $viewport['breakpoint_outcome']);
                $this->assertSame('pending', $viewport['geometry_outcome']);
                $this->assertNull($viewport['measurements']);
            }
        }
        $this->assertCount(2, $disk->allFiles('layout-snapshots'));

        $replayed = $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $patchPlan],
        )->assertOk();
        $this->assertSame($patched['snapshot_id'], $replayed->json('layout_snapshot.snapshot_id'));
        $this->assertCount(2, $disk->allFiles('layout-snapshots'));
        Http::assertNothingSent();
    }

    public function test_site_constraint_patch_recompiles_every_page_and_records_root_constraint_digests(): void
    {
        Http::preventStrayRequests();
        $disk = Storage::fake('layout-snapshots-test');
        $candidate = $this->candidate();
        $patchPlan = $this->patchPlan($candidate, [
            [
                'target_id' => '$constraints',
                'viewport' => ['min_width' => 320],
                'problem' => 'content_too_wide',
                'operation' => ['type' => 'set_content_max_width', 'value' => 720],
            ],
            [
                'target_id' => '$constraints',
                'viewport' => ['min_width' => 320],
                'problem' => 'container_too_wide',
                'operation' => ['type' => 'set_container_max_width', 'value' => 1040],
            ],
            [
                'target_id' => '$constraints',
                'viewport' => ['min_width' => 320],
                'problem' => 'action_too_small',
                'operation' => ['type' => 'set_minimum_action_height', 'value' => 48],
            ],
        ]);

        $response = $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $patchPlan],
        )->assertCreated()
            ->assertJsonPath('layout_snapshot.constraints.container_max_width_px', 1040)
            ->assertJsonPath('layout_snapshot.constraints.content_max_width_px', 720)
            ->assertJsonPath('layout_snapshot.constraints.minimum_action_height_px', 48)
            ->assertJsonPath('layout_snapshot.lineage.delta.operations.0.target.kind', 'constraints')
            ->assertJsonPath('layout_snapshot.lineage.delta.operations.0.target.page_key', null)
            ->assertJsonPath(
                'layout_snapshot.lineage.delta.operations.0.before_digest',
                LayoutSnapshot::digestValue($candidate['constraints']),
            );

        $patched = $response->json('layout_snapshot');
        $this->assertSame(
            LayoutSnapshot::digestValue($patched['constraints']),
            $patched['lineage']['delta']['operations'][2]['after_digest'],
        );
        $this->assertSame(
            $patched['lineage']['delta']['operations'][0]['after_digest'],
            $patched['lineage']['delta']['operations'][1]['before_digest'],
        );
        $this->assertSame(
            $patched['lineage']['delta']['operations'][1]['after_digest'],
            $patched['lineage']['delta']['operations'][2]['before_digest'],
        );
        $this->assertSame($this->semanticArtifacts($candidate), $this->semanticArtifacts($patched));
        foreach ($patched['pages'] as $index => $page) {
            $this->assertSame(1040, $page['layout']['ast']['constraints']['container_max_width_px']);
            $this->assertSame(720, $page['layout']['ast']['constraints']['content_max_width_px']);
            $this->assertSame(48, $page['layout']['ast']['constraints']['minimum_action_height_px']);
            $this->assertSame('1040px', $page['layout']['ast']['tokens']['container']['site']);
            $this->assertSame('720px', $page['layout']['ast']['tokens']['container']['content']);
            $this->assertSame('48px', $page['layout']['ast']['tokens']['size']['action-min']);
            $this->assertStringContainsString('--mh-container-site: 1040px', $page['layout']['css']);
            $this->assertStringContainsString('--mh-container-content: 720px', $page['layout']['css']);
            $this->assertStringContainsString('--mh-size-action-min: 48px', $page['layout']['css']);
            $this->assertNotSame($candidate['pages'][$index]['layout']['ast_digest'], $page['layout']['ast_digest']);
            $this->assertNotSame($candidate['pages'][$index]['layout']['css_digest'], $page['layout']['css_digest']);
        }
        $this->assertCount(2, $disk->allFiles('layout-snapshots'));
        Http::assertNothingSent();
    }

    public function test_returns_422_when_base_ast_digest_does_not_match_selected_page(): void
    {
        Storage::fake('layout-snapshots-test');
        $candidate = $this->candidate();
        $patchPlan = $this->patchPlan($candidate, [[
            'target_id' => 'layout.region.hero',
            'viewport' => ['min_width' => 1024],
            'problem' => 'column_imbalance',
            'operation' => ['type' => 'set_column_ratio', 'value' => '2fr 3fr'],
        ]]);
        $patchPlan['base_ast_digest'] = str_repeat('f', 64);

        $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $patchPlan],
        )->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_layout_snapshot_patch');
        $this->assertCount(1, Storage::disk('layout-snapshots-test')->allFiles('layout-snapshots'));
    }

    public function test_returns_422_for_unsupported_mobile_order_cross_breakpoint_or_scoped_site_constraint(): void
    {
        Storage::fake('layout-snapshots-test');
        $candidate = $this->candidate();

        $mobileOrder = $this->patchPlan($candidate, [[
            'target_id' => 'layout.region.hero',
            'viewport' => ['max_width' => 719],
            'problem' => 'incorrect_mobile_order',
            'operation' => ['type' => 'set_mobile_order', 'value' => 2],
        ]]);
        $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $mobileOrder],
        )->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('patch_plan.issues.0.operation.type');

        $crossing = $this->patchPlan($candidate, [[
            'target_id' => 'layout.region.hero',
            'viewport' => ['min_width' => 500, 'max_width' => 900],
            'problem' => 'column_imbalance',
            'operation' => ['type' => 'set_column_ratio', 'value' => '2fr 3fr'],
        ]]);
        $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $crossing],
        )->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_layout_snapshot_patch');

        $scopedConstraint = $this->patchPlan($candidate, [[
            'target_id' => '$constraints',
            'viewport' => ['max_width' => 719],
            'problem' => 'action_too_small',
            'operation' => ['type' => 'set_minimum_action_height', 'value' => 48],
        ]]);
        $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $scopedConstraint],
        )->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('patch_plan.issues.0.viewport');
        $this->assertCount(1, Storage::disk('layout-snapshots-test')->allFiles('layout-snapshots'));
    }

    public function test_returns_422_for_unknown_nested_contract_fields(): void
    {
        Storage::fake('layout-snapshots-test');
        $candidate = $this->candidate();
        $patchPlan = $this->patchPlan($candidate, [[
            'target_id' => 'layout.region.hero',
            'viewport' => ['min_width' => 1024],
            'problem' => 'column_imbalance',
            'operation' => ['type' => 'set_column_ratio', 'value' => '2fr 3fr'],
        ]]);
        $patchPlan['issues'][0]['operation']['css'] = 'display:none';

        $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $patchPlan],
        )->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('patch_plan.issues.0.operation');
        $this->assertCount(1, Storage::disk('layout-snapshots-test')->allFiles('layout-snapshots'));
    }

    public function test_rejects_a_problem_owned_by_a_later_design_stage(): void
    {
        Storage::fake('layout-snapshots-test');
        $candidate = $this->candidate();
        $patchPlan = $this->patchPlan($candidate, [[
            'target_id' => 'layout.image',
            'viewport' => ['min_width' => 1024],
            'problem' => 'inconsistent_surface',
            'operation' => ['type' => 'set_object_fit', 'value' => 'cover'],
        ]]);

        $this->withToken('test-token')->postJson(
            '/api/v1/layout-snapshots/'.$candidate['snapshot_id'].'/patches',
            ['contract_version' => '1.0', 'page_key' => 'home', 'patch_plan' => $patchPlan],
        )->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('patch_plan.issues.0.problem');
        $this->assertCount(1, Storage::disk('layout-snapshots-test')->allFiles('layout-snapshots'));
    }

    public function test_returns_404_for_an_unknown_candidate_and_requires_service_authentication(): void
    {
        Storage::fake('layout-snapshots-test');
        $snapshotId = 'ls_'.str_repeat('a', 64);
        $payload = [
            'contract_version' => '1.0',
            'page_key' => 'home',
            'patch_plan' => [
                'contract_version' => '1.0',
                'stage' => 'layout',
                'base_ast_digest' => str_repeat('b', 64),
                'issues' => [[
                    'target_id' => 'layout.region.hero',
                    'viewport' => ['min_width' => 1024],
                    'problem' => 'column_imbalance',
                    'operation' => ['type' => 'set_column_ratio', 'value' => '2fr 3fr'],
                ]],
            ],
        ];

        $this->postJson('/api/v1/layout-snapshots/'.$snapshotId.'/patches', $payload)
            ->assertUnauthorized();
        $this->withToken('test-token')->postJson('/api/v1/layout-snapshots/'.$snapshotId.'/patches', $payload)
            ->assertNotFound()
            ->assertJsonPath('type', 'layout_snapshot_not_found');
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        return $this->withToken('test-token')->postJson('/api/v1/layout-snapshots', [
            'contract_version' => '1.0',
            'wireframe_ast' => WireframeV2Fixture::document(),
            'validation_viewports' => [390, 768, 1440],
        ])->assertCreated()->json('layout_snapshot');
    }

    /** @param list<array<string,mixed>> $issues @return array<string,mixed> */
    private function patchPlan(array $candidate, array $issues): array
    {
        return [
            'contract_version' => '1.0',
            'stage' => 'layout',
            'base_ast_digest' => $candidate['pages'][0]['layout']['ast_digest'],
            'issues' => $issues,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function semanticArtifacts(array $snapshot): array
    {
        return array_map(static fn (array $page): array => [
            'page_key' => $page['page_key'],
            'route' => $page['route'],
            'path' => $page['path'],
            'title' => $page['title'],
            'source_html' => $page['source_html'],
        ], $snapshot['pages']);
    }

    /** @return list<array<string,string>> */
    private function layoutArtifacts(array $snapshot): array
    {
        return array_map(static fn (array $page): array => [
            'ast_digest' => $page['layout']['ast_digest'],
            'css_digest' => $page['layout']['css_digest'],
        ], $snapshot['pages']);
    }

    /** @return array<string,mixed> */
    private function rule(array $snapshot, string $pageKey, string $ruleId): array
    {
        foreach ($snapshot['pages'] as $page) {
            if ($page['page_key'] !== $pageKey) {
                continue;
            }
            foreach ($page['layout']['ast']['rules'] as $rule) {
                if ($rule['id'] === $ruleId) {
                    return $rule;
                }
            }
        }

        $this->fail("Layout rule {$ruleId} was not found on page {$pageKey}.");
    }
}
