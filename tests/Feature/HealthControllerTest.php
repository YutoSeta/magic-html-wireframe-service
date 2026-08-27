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
}
