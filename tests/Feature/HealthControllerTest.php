<?php

namespace Tests\Feature;

use App\Layout\CommonLayoutCompiler;
use App\Layout\LayoutSnapshotBuilder;
use App\Layout\LayoutSnapshotFreezer;
use App\Layout\LayoutSnapshotPatcher;
use App\Layout\LayoutSnapshotStore;
use App\Layout\LayoutSnapshotVerifier;
use App\Layout\SemanticLayoutHtmlRenderer;
use App\WireframePresentation\WireframePresentationPreset;
use App\WireframePresentation\WireframePresenter;
use Tests\TestCase;
use YutoSeta\MagicHtmlLayout\LayoutAstCompiler;
use YutoSeta\MagicHtmlLayout\LayoutAstPatcher as PageLayoutAstPatcher;
use YutoSeta\MagicHtmlLayout\LayoutAstValidator;
use YutoSeta\MagicHtmlLayout\LayoutSnapshot;

final class HealthControllerTest extends TestCase
{
    public function test_health_returns_liveness_without_authentication(): void
    {
        config()->set('app.name', 'Magic HTML Health Test');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'Magic HTML Health Test',
            ]);
    }

    public function test_capability_metadata_advertises_v1_v2_and_the_fixed_wireframe_profile(): void
    {
        $this->getJson('/api')
            ->assertOk()
            ->assertJsonPath('contract_version', '1.0')
            ->assertJsonPath('supported_wireframe_ast_versions', [1, 2])
            ->assertJsonPath('default_wireframe_ast_version', 1)
            ->assertJsonPath('wireframe_decorate_profile', 'wireframe-neutral-v1')
            ->assertJsonPath('layout_snapshot.layout_ast_version', 2)
            ->assertJsonPath('layout_snapshot.lifecycle', ['candidate', 'frozen'])
            ->assertJsonPath('wireframe_presentation_profile', 'wireframe-presentation-v1')
            ->assertJsonPath('operations.6', 'POST /api/v1/layout-snapshots/{layoutSnapshot}/freeze')
            ->assertJsonPath('operations.7', 'POST /api/v1/layout-snapshots/{layoutSnapshot}/patches');
    }

    public function test_readiness_advertises_the_same_v2_contract_metadata(): void
    {
        config()->set([
            'services.openai.key' => 'test-key',
            'wireframe.idempotency.store' => 'array',
        ]);

        $layoutContractInstalled = collect([
            'vendor/yutoseta/magic-html-contracts/schemas/v1/layout/create-snapshot-request.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/layout/create-snapshot-result.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/layout/freeze-snapshot-request.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/layout/freeze-snapshot-result.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/layout/patch-snapshot-request.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/layout/patch-snapshot-result.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/layout/reference.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/layout/snapshot.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/wireframe/presentation-request.json',
            'vendor/yutoseta/magic-html-contracts/schemas/v1/wireframe/presentation-result.json',
        ])->every(fn (string $path): bool => is_file(base_path($path)));
        $layoutAstV2Installed = class_exists(LayoutAstCompiler::class)
            && class_exists(PageLayoutAstPatcher::class)
            && class_exists(LayoutAstValidator::class)
            && class_exists(LayoutSnapshot::class)
            && is_file(base_path('vendor/yutoseta/magic-html-layout-ast/resources/layout-ast-v2.schema.json'));
        $commonLayoutCompilerInstalled = class_exists(CommonLayoutCompiler::class)
            && class_exists(SemanticLayoutHtmlRenderer::class);
        $layoutSnapshotRuntimeInstalled = class_exists(LayoutSnapshotBuilder::class)
            && class_exists(LayoutSnapshotFreezer::class)
            && class_exists(LayoutSnapshotPatcher::class)
            && class_exists(LayoutSnapshotStore::class)
            && class_exists(LayoutSnapshotVerifier::class);
        $wireframePresenterInstalled = class_exists(WireframePresentationPreset::class)
            && class_exists(WireframePresenter::class);
        $formalLayoutPathReady = $layoutContractInstalled
            && $layoutAstV2Installed
            && $commonLayoutCompilerInstalled
            && $layoutSnapshotRuntimeInstalled
            && $wireframePresenterInstalled;

        $this->getJson('/api/__verify')
            ->assertStatus($formalLayoutPathReady ? 200 : 503)
            ->assertJsonPath('status', $formalLayoutPathReady ? 'ok' : 'degraded')
            ->assertJsonPath('supported_wireframe_ast_versions', [1, 2])
            ->assertJsonPath('wireframe_decorate_profile', 'wireframe-neutral-v1')
            ->assertJsonPath('layout_snapshot_profile', 'layout-snapshot-v1')
            ->assertJsonPath('layout_ast_version', 2)
            ->assertJsonPath('wireframe_presentation_profile', 'wireframe-presentation-v1')
            ->assertJsonPath('checks.layout_contract_installed', $layoutContractInstalled)
            ->assertJsonPath('checks.layout_ast_v2_installed', $layoutAstV2Installed)
            ->assertJsonPath('checks.common_layout_compiler_installed', $commonLayoutCompilerInstalled)
            ->assertJsonPath('checks.layout_snapshot_runtime_installed', $layoutSnapshotRuntimeInstalled)
            ->assertJsonPath('checks.wireframe_presenter_installed', $wireframePresenterInstalled);
    }
}
