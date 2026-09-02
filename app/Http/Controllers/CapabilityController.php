<?php

namespace App\Http\Controllers;

use App\Http\Resources\CapabilityResource;
use App\Layout\CommonLayoutCompiler;
use App\Layout\LayoutSnapshotBuilder;
use App\Layout\LayoutSnapshotFreezer;
use App\Layout\LayoutSnapshotPatcher;
use App\Layout\LayoutSnapshotStore;
use App\Layout\LayoutSnapshotVerifier;
use App\Layout\SemanticLayoutHtmlRenderer;
use App\WireframePresentation\WireframePresentationPreset;
use App\WireframePresentation\WireframePresenter;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use YutoSeta\MagicHtmlLayout\LayoutAstCompiler;
use YutoSeta\MagicHtmlLayout\LayoutAstPatcher as PageLayoutAstPatcher;
use YutoSeta\MagicHtmlLayout\LayoutAstValidator;
use YutoSeta\MagicHtmlLayout\LayoutSnapshot;

final class CapabilityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResource
    {
        return new CapabilityResource([]);
    }

    public function verify(CacheManager $cache): JsonResponse
    {
        $store = config('wireframe.idempotency.store');
        try {
            $cache->store(is_string($store) && $store !== '' ? $store : null)
                ->get('wireframe-idempotency-readiness');
            $idempotencyStore = true;
        } catch (\Throwable) {
            $idempotencyStore = false;
        }

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

        $checks = [
            'contract_installed' => is_file(base_path('vendor/yutoseta/magic-html-contracts/openapi/tier1.json')),
            'layout_contract_installed' => $layoutContractInstalled,
            'layout_ast_v2_installed' => $layoutAstV2Installed,
            'common_layout_compiler_installed' => $commonLayoutCompilerInstalled,
            'layout_snapshot_runtime_installed' => $layoutSnapshotRuntimeInstalled,
            'wireframe_presenter_installed' => $wireframePresenterInstalled,
            'generator' => (string) config('services.openai.key') !== '',
            'idempotency_store' => $idempotencyStore,
        ];
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'service' => 'magic-html-wireframe-service',
            'tier' => 0,
            'status' => $ready ? 'ok' : 'degraded',
            'contract_version' => '1.0',
            'supported_wireframe_ast_versions' => [1, 2],
            'generation_modes' => ['monolithic', 'section_parallel'],
            'wireframe_decorate_profile' => 'wireframe-neutral-v1',
            'layout_snapshot_profile' => 'layout-snapshot-v1',
            'layout_ast_version' => 2,
            'wireframe_presentation_profile' => 'wireframe-presentation-v1',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
