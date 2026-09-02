<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CapabilityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => 'magic-html-wireframe-service',
            'tier' => 0,
            'contract_version' => '1.0',
            'supported_wireframe_ast_versions' => [1, 2],
            'default_wireframe_ast_version' => 1,
            'generation_modes' => ['monolithic', 'section_parallel'],
            'default_generation_mode' => 'monolithic',
            'wireframe_decorate_profile' => 'wireframe-neutral-v1',
            'layout_snapshot' => [
                'contract_version' => '1.0',
                'profile' => 'layout-snapshot-v1',
                'layout_ast_version' => 2,
                'layout_profile' => 'geometry-layout-v2',
                'reference_profile' => 'layout-snapshot-reference-v1',
                'lifecycle' => ['candidate', 'frozen'],
                'validation_viewports_px' => [390, 768, 1440],
            ],
            'wireframe_presentation_profile' => 'wireframe-presentation-v1',
            'documentation' => url('/api/__verify'),
            'health' => url('/up'),
            'operations' => [
                'POST /api/v1/wireframes',
                'POST /api/v1/wireframe-jobs',
                'GET /api/v1/wireframe-jobs/{job}',
                'POST /api/v1/wireframes/materialize',
                'POST /api/v1/layout-snapshots',
                'GET /api/v1/layout-snapshots/{layoutSnapshot}',
                'POST /api/v1/layout-snapshots/{layoutSnapshot}/freeze',
                'POST /api/v1/layout-snapshots/{layoutSnapshot}/patches',
                'POST /api/v1/wireframe-presentations',
            ],
            'write_safety' => [
                'idempotency_key' => [
                    'header' => 'Idempotency-Key',
                    'min_length' => 8,
                    'max_length' => 200,
                    'exact_request_bytes' => true,
                    'replay_header' => 'Idempotent-Replayed',
                ],
                'stored_input' => 'sha256_only',
                'stored_response' => [
                    'version_1' => 'content_free_plaintext_immutable',
                    'version_2' => 'application_encrypted_ttl',
                    'version_2_ttl_seconds' => max(60, (int) config('wireframe.idempotency.v2_response_ttl_seconds', 86400)),
                ],
                'layout_snapshots' => [
                    'content_addressed' => true,
                    'write_once' => true,
                    'encrypted' => true,
                    'ttl_seconds' => max(600, (int) config('wireframe.layout_snapshots.ttl_seconds', 604800)),
                ],
            ],
        ];
    }
}
