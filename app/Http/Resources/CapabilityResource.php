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
            'documentation' => url('/api/__verify'),
            'health' => url('/up'),
            'operations' => [
                'POST /api/v1/wireframes',
                'POST /api/v1/wireframe-jobs',
                'GET /api/v1/wireframe-jobs/{job}',
                'POST /api/v1/wireframes/materialize',
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
            ],
        ];
    }
}
