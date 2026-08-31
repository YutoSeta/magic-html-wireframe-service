<?php

namespace App\Http\Controllers;

use App\Http\Resources\CapabilityResource;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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

        $checks = [
            'contract_installed' => is_file(base_path('vendor/yutoseta/magic-html-contracts/openapi/tier1.json')),
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
            'wireframe_decorate_profile' => 'wireframe-neutral-v1',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
