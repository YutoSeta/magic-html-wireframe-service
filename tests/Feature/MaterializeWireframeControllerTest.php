<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\WireframeV2Fixture;
use Tests\TestCase;

final class MaterializeWireframeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wireframe.service_token', 'test-token');
    }

    public function test_materializes_a_canonical_self_contained_neutral_html_file_set_without_a_provider(): void
    {
        Http::preventStrayRequests();
        $payload = $this->payload();

        $first = $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload);
        $payload['wireframe_ast'] = [
            'pages' => $payload['wireframe_ast']['pages'],
            'version' => $payload['wireframe_ast']['version'],
        ];
        $second = $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload);

        $first->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('contract_version', '1.0')
            ->assertJsonPath('entry_path', 'page-home-scriptalert1script.html')
            ->assertJsonPath('telemetry.operation', 'wireframes.materialize')
            ->assertJsonPath('telemetry.renderer', 'neutral-layout-html')
            ->assertJsonPath('telemetry.provider', 'deterministic')
            ->assertJsonPath('telemetry.model', null)
            ->assertJsonPath('telemetry.response_id', null)
            ->assertJsonPath('telemetry.input_tokens', 0)
            ->assertJsonPath('telemetry.cached_input_tokens', 0)
            ->assertJsonPath('telemetry.output_tokens', 0)
            ->assertJsonPath('telemetry.reasoning_tokens', 0)
            ->assertJsonPath('telemetry.estimated_cost', null)
            ->assertJsonPath('telemetry.provider_request_count', 0)
            ->assertJsonPath('telemetry.semantic_attempt_count', 0)
            ->assertJsonPath('telemetry.retry_count', 0)
            ->assertJsonPath('telemetry.provider_duration_ms', 0)
            ->assertJsonPath('telemetry.rate_card', null)
            ->assertJsonCount(2, 'files')
            ->assertJsonCount(2, 'file_manifest');

        $sourceDigest = $first->json('source_digest');
        $files = $first->json('files');
        $manifest = $first->json('file_manifest');
        foreach ($files as $index => $file) {
            $this->assertSame(['path', 'mime', 'content_base64'], array_keys($file));
            $this->assertSame(['page_key', 'path', 'size', 'sha256'], array_keys($manifest[$index]));
            $this->assertSame($file['path'], $manifest[$index]['path']);
            $content = base64_decode($file['content_base64'], true);
            $this->assertIsString($content);
            $this->assertSame(strlen($content), $manifest[$index]['size']);
            $this->assertSame(hash('sha256', $content), $manifest[$index]['sha256']);
        }
        $html = base64_decode($files[0]['content_base64'], true);
        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $sourceDigest);
        $this->assertStringStartsWith("<!doctype html>\n", $html);
        $this->assertStringContainsString('Content-Security-Policy', $html);
        $this->assertStringContainsString('default-src \'none\'', $html);
        $this->assertStringContainsString('Home &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('Home <script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('src=', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('url(', $html);
        $this->assertStringNotContainsString('animation', $html);
        $this->assertStringNotContainsString('transition', $html);
        $this->assertStringNotContainsString('box-shadow', $html);
        $this->assertStringNotContainsString('border-radius', $html);
        $this->assertIsInt($first->json('telemetry.render_duration_ms'));
        $this->assertGreaterThanOrEqual(0, $first->json('telemetry.render_duration_ms'));
        $this->assertSame($first->json('source_digest'), $second->json('source_digest'));
        $this->assertSame($first->json('files'), $second->json('files'));
        $this->assertSame($first->json('file_manifest'), $second->json('file_manifest'));
        Http::assertNothingSent();
    }

    public function test_requires_service_authentication(): void
    {
        $this->postJson('/api/v1/wireframes/materialize', $this->payload())
            ->assertUnauthorized();
    }

    public function test_returns_422_for_duplicate_section_keys(): void
    {
        $payload = $this->payload();
        $payload['wireframe_ast']['pages'][0]['sections'][1]['key'] = 'hero';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');
    }

    public function test_assigns_unique_preview_paths_when_page_keys_have_the_same_slug(): void
    {
        $payload = $this->payload();
        $payload['wireframe_ast']['pages'][0]['key'] = 'Home';
        $payload['wireframe_ast']['pages'][1]['key'] = 'home';

        $response = $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertOk()
            ->assertJsonPath('entry_path', 'page-home.html');

        $paths = array_column($response->json('files'), 'path');
        $this->assertCount(2, array_unique(array_map(strtolower(...), $paths)));
        $this->assertMatchesRegularExpression('/\Apage-home-[a-f0-9]{12}\.html\z/D', $paths[1]);
    }

    public function test_rejects_fields_outside_the_materialize_contract(): void
    {
        $payload = $this->payload();
        $payload['wireframe_ast']['theme'] = ['remote_css' => 'https://example.test/theme.css'];

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('wireframe_ast');
    }

    public function test_v1_keeps_rejecting_unknown_nested_page_and_section_fields(): void
    {
        $payload = $this->payload();
        $payload['wireframe_ast']['pages'][0]['remote'] = true;

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('wireframe_ast.pages.0');

        $payload = $this->payload();
        $payload['wireframe_ast']['pages'][0]['sections'][0]['remote'] = true;

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'validation_failed')
            ->assertJsonValidationErrors('wireframe_ast.pages.0.sections.0');
    }

    public function test_materializes_a_content_bearing_nested_v2_wireframe_with_fixed_decoration_and_real_form_controls(): void
    {
        Http::preventStrayRequests();

        $response = $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $this->v2Payload())
            ->assertOk()
            ->assertJsonPath('contract_version', '1.0')
            ->assertJsonPath('wireframe_decorate_ast.preset', 'wireframe-neutral-v1')
            ->assertJsonPath('wireframe_decorate_ast.canvas.background', '#ffffff')
            ->assertJsonPath('wireframe_decorate_ast.surfaces.container', 'transparent')
            ->assertJsonPath('wireframe_decorate_ast.surfaces.image', '#d1d5db')
            ->assertJsonPath('telemetry.renderer', 'semantic-wireframe-html')
            ->assertJsonPath('telemetry.renderer_version', '2.0');

        $html = base64_decode($response->json('files.0.content_base64'), true);
        $this->assertIsString($html);
        $this->assertStringContainsString('<html lang="ja">', $html);
        $this->assertStringContainsString('<h1 id="hero-title"', $html);
        $this->assertStringContainsString('作り直す前に、まず直せるか診断。', $html);
        $this->assertStringContainsString('&lt;安心&gt;', $html);
        $this->assertStringNotContainsString('<安心>', $html);
        $this->assertStringContainsString('<figure id="hero-image"', $html);
        $this->assertStringContainsString('background:#d1d5db', $html);
        $this->assertStringContainsString('background:transparent', $html);
        $this->assertStringContainsString('margin:4px;padding:8px', $html);
        $this->assertStringContainsString('<a id="hero-action"', $html);
        $this->assertStringContainsString('href="page-privacy.html"', $html);
        $this->assertStringContainsString('href="page-legal.html"', $html);
        $this->assertStringContainsString('<form id="free-consultation"', $html);
        $this->assertStringContainsString('m-form="free-consultation"', $html);
        $this->assertStringContainsString('<input type="email" name="email" m-field="email"', $html);
        $this->assertStringContainsString('<textarea name="detail" m-field="detail"', $html);
        $this->assertStringContainsString('<select name="symptom" m-field="symptom"', $html);
        $this->assertStringContainsString('<input type="checkbox" name="privacy"', $html);
        $this->assertStringContainsString('<button id="submit-consultation"', $html);
        $this->assertStringNotContainsString('section-meta', $html);
        $this->assertStringNotContainsString('class="roles"', $html);
        $this->assertStringNotContainsString('>Item<', $html);
        $this->assertStringNotContainsString('>Action<', $html);
        $this->assertStringNotContainsString('src=', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('box-shadow', $html);
        $this->assertStringNotContainsString('border-radius', $html);
        $this->assertStringNotContainsString('animation', $html);
        Http::assertNothingSent();
    }

    public function test_v2_materialization_is_deterministic_for_reordered_object_keys(): void
    {
        $firstPayload = $this->v2Payload();
        $document = $firstPayload['wireframe_ast'];
        $secondPayload = [
            'wireframe_ast' => [
                'pages' => $document['pages'],
                'locale' => $document['locale'],
                'version' => $document['version'],
            ],
            'contract_version' => '1.0',
        ];

        $first = $this->withToken('test-token')->postJson('/api/v1/wireframes/materialize', $firstPayload)->assertOk();
        $second = $this->withToken('test-token')->postJson('/api/v1/wireframes/materialize', $secondPayload)->assertOk();

        $this->assertSame($first->json('source_digest'), $second->json('source_digest'));
        $this->assertSame($first->json('files'), $second->json('files'));
        $this->assertSame($first->json('wireframe_decorate_ast'), $second->json('wireframe_decorate_ast'));
    }

    public function test_v2_rejects_unknown_leaf_fields_and_form_controls_outside_a_form(): void
    {
        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][0]['root']['children'][1]['children'][0]['children'][1]['src'] = 'https://example.test/image.jpg';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');

        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][0]['root']['children'][1]['children'][1]['children'][] = WireframeV2Fixture::input(
            'outside-form', 'text', '不正な入力', 'outside', null, false,
        );

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');
    }

    public function test_v2_rejects_duplicate_ids_and_unresolved_local_links(): void
    {
        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][0]['root']['children'][2]['children'][0]['id'] = 'hero-title';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');

        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][0]['root']['children'][0]['children'][1]['children'][0]['href'] = '#missing-target';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');
    }

    public function test_v2_rejects_unsafe_paths_and_incomplete_or_ambiguous_forms(): void
    {
        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][0]['root']['children'][2]['children'][1]['href'] = '/\\evil.example';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');

        $payload = $this->v2Payload();
        array_pop($payload['wireframe_ast']['pages'][0]['root']['children'][1]['children'][2]['children'][1]['children']);

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');

        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][0]['root']['children'][1]['children'][2]['children'][1]['children'][1]['name'] = 'name';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');
    }

    public function test_v2_requires_a_canonical_home_first_unique_route_map_and_resolvable_link_scope(): void
    {
        $payload = $this->v2Payload();
        [$payload['wireframe_ast']['pages'][0], $payload['wireframe_ast']['pages'][1]] = [
            $payload['wireframe_ast']['pages'][1],
            $payload['wireframe_ast']['pages'][0],
        ];

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');

        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][2]['path'] = '/privacy';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');

        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][0]['root']['children'][2]['children'][1]['href'] = '/legal#missing';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'invalid_wireframe');
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'contract_version' => '1.0',
            'wireframe_ast' => [
                'version' => 1,
                'pages' => [
                    [
                        'key' => 'Home <script>alert(1)</script>',
                        'sections' => [
                            ['key' => 'hero', 'composition' => 'hero', 'roles' => ['Eyebrow', 'Title', 'Text', 'Actions', 'Image']],
                            ['key' => 'features', 'composition' => 'feature-grid', 'roles' => ['Title', 'Items']],
                        ],
                    ],
                    [
                        'key' => 'contact',
                        'sections' => [
                            ['key' => 'contact', 'composition' => 'contact', 'roles' => ['Title', 'Text', 'Actions']],
                            ['key' => 'faq', 'composition' => 'faq', 'roles' => ['Title', 'Items']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function v2Payload(): array
    {
        return [
            'contract_version' => '1.0',
            'wireframe_ast' => WireframeV2Fixture::document(),
        ];
    }
}
