<?php

namespace Tests\Feature;

use Tests\TestCase;

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
            ->assertJsonPath('wireframe_decorate_profile', 'wireframe-neutral-v1');
    }

    public function test_readiness_advertises_the_same_v2_contract_metadata(): void
    {
        config()->set([
            'services.openai.key' => 'test-key',
            'wireframe.idempotency.store' => 'array',
        ]);

        $this->getJson('/api/__verify')
            ->assertOk()
            ->assertJsonPath('supported_wireframe_ast_versions', [1, 2])
            ->assertJsonPath('wireframe_decorate_profile', 'wireframe-neutral-v1');
    }
}
