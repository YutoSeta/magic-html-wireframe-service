<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AdminTransportTest extends TestCase
{
    public function test_it_rejects_an_unauthenticated_admin_manifest_request(): void
    {
        $this->getJson('/__admin/manifest')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'A valid admin transport bearer token is required.');
    }

    public function test_it_exposes_the_admin_manifest_to_the_authenticated_control_plane(): void
    {
        $response = $this
            ->withToken('test-admin-token')
            ->getJson('/__admin/manifest');

        $response
            ->assertOk()
            ->assertJsonPath('contract_version', '1.0');

        $response->assertJsonCount(0, 'operations');
    }
}
