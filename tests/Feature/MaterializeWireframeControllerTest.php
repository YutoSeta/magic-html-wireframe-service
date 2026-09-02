<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\WireframeV2Fixture;
use Tests\TestCase;
use YutoSeta\MagicHtmlDesign\DesignMarker;
use YutoSeta\MagicHtmlDesign\Stage\TargetCoverageEvaluator;

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
            ->assertJsonPath('wireframe_decorate_ast.borders.leaf.width_px', 0)
            ->assertJsonPath('telemetry.renderer', 'semantic-wireframe-html')
            ->assertJsonPath('handoff.profile', 'styler-input-v1')
            ->assertJsonPath('handoff.entry_path', 'page-home.html')
            ->assertJsonPath('telemetry.renderer_version', '2.2');

        $html = base64_decode($response->json('files.0.content_base64'), true);
        $this->assertIsString($html);
        $this->assertStringContainsString('<html lang="ja">', $html);
        $this->assertStringContainsString('<h1 id="hero-title"', $html);
        $this->assertStringContainsString('data-wf-semantic="document" data-wf-stage="none" data-wf-emphasis="neutral" data-mh-role="Page"', $html);
        $this->assertStringContainsString('data-wf-semantic="section" data-wf-stage="attention" data-wf-emphasis="primary" data-mh-role="Section" data-mh-composition="hero"', $html);
        $this->assertStringContainsString('data-wf-type="Text" data-mh-role="Title"', $html);
        $this->assertStringContainsString('data-wf-type="Image" data-aspect="16:9" data-mh-role="Image"', $html);
        $this->assertStringContainsString('data-wf-type="Link" data-emphasis="primary" data-mh-role="Link"', $html);
        $this->assertStringContainsString('data-wf-type="Button" data-emphasis="primary" data-mh-role="Btn"', $html);
        $this->assertStringContainsString('data-wf-type="Input" data-mh-role="Group" data-mh-qualifier="Field"', $html);
        $this->assertStringContainsString('data-wf-type="Checkbox" data-mh-role="Group" data-mh-qualifier="Field"', $html);
        $this->assertStringContainsString('data-mh-role="Input" data-mh-qualifier="Text"', $html);
        $this->assertStringContainsString('data-mh-role="Input" data-mh-qualifier="Textarea"', $html);
        $this->assertStringContainsString('data-mh-role="Input" data-mh-qualifier="Select"', $html);
        $this->assertStringContainsString('data-mh-role="Input" data-mh-qualifier="Checkbox"', $html);
        $this->assertStringContainsString('作り直す前に、まず直せるか診断。', $html);
        $this->assertStringContainsString('&lt;安心&gt;', $html);
        $this->assertStringNotContainsString('<安心>', $html);
        $this->assertStringContainsString('<figure id="hero-image"', $html);
        $this->assertStringContainsString('background:#d1d5db', $html);
        $this->assertStringContainsString('data-wireframe-presentation="wireframe-neutral-v1"', $html);
        $this->assertStringContainsString('background:transparent', $html);
        $this->assertStringContainsString('margin:2px;padding:6px', $html);
        $this->assertStringContainsString('background:#171717;color:#fff', $html);
        $this->assertStringContainsString('color:#172554;text-decoration:underline', $html);
        $this->assertStringContainsString('background:#171717;color:#fff;border:0;text-decoration:none', $html);
        $this->assertStringContainsString('background:#f3f4f6', $html);
        $this->assertStringContainsString('border:1px solid #a3a3a3', $html);
        $this->assertStringContainsString('word-break:break-all', $html);
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

        $handoffHtml = base64_decode($response->json('handoff.files.0.content_base64'), true);
        $this->assertIsString($handoffHtml);
        $this->assertStringContainsString('<meta name="wireframe-handoff-profile" content="styler-input-v1">', $handoffHtml);
        $this->assertStringContainsString('data-wf-semantic="section"', $handoffHtml);
        $this->assertStringContainsString('m-form="free-consultation"', $handoffHtml);
        $this->assertStringNotContainsString('<style', $handoffHtml);
        $this->assertStringNotContainsString('wireframe-decoration-profile', $handoffHtml);
        $this->assertStringNotContainsString('wireframe-neutral-v1', $handoffHtml);
        $this->assertStringNotContainsString('background:#d1d5db', $handoffHtml);
        $this->assertSame(
            hash('sha256', $handoffHtml),
            $response->json('handoff.file_manifest.0.sha256'),
        );
        $this->assertNotSame($response->json('source_digest'), $response->json('handoff.source_digest'));
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
        $this->assertSame($first->json('handoff'), $second->json('handoff'));
    }

    public function test_v11_promotes_the_preview_geometry_to_a_frozen_layout_handoff(): void
    {
        Http::preventStrayRequests();
        $payload = $this->v2Payload();
        $payload['contract_version'] = '1.1';

        $response = $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertOk()
            ->assertJsonPath('contract_version', '1.1')
            ->assertJsonPath('layout.profile', 'approved-wireframe-layout-v1')
            ->assertJsonPath('layout.status', 'frozen')
            ->assertJsonPath('handoff.profile', 'styler-input-v2')
            ->assertJsonPath('wireframe_skin_ast.preset', 'wireframe-neutral-skin-v1')
            ->assertJsonPath('wireframe_decor_ast.preset', 'wireframe-structure-decor-v1')
            ->assertJsonMissingPath('wireframe_decorate_ast')
            ->assertJsonCount(3, 'layout.pages')
            ->assertJsonCount(3, 'handoff.approved_layouts');

        $preview = base64_decode($response->json('files.0.content_base64'), true);
        $handoff = base64_decode($response->json('handoff.files.0.content_base64'), true);
        $layout = $response->json('handoff.approved_layouts.0');
        $this->assertIsString($preview);
        $this->assertIsString($handoff);
        $this->assertIsArray($layout);
        $this->assertStringContainsString('data-wireframe-layout="approved-wireframe-layout-v1"', $preview);
        $this->assertStringContainsString('data-wireframe-presentation="wireframe-skin-decor-v1"', $preview);
        $this->assertStringContainsString('@layer mh-layout', $layout['css']);
        $this->assertStringContainsString('@container (max-width: calc(45rem - 0.02px))', $layout['css']);
        $this->assertStringContainsString('grid-template-columns: 3fr 2fr', $layout['css']);
        $this->assertStringContainsString('min-height: var(--mh-size-action-min)', $layout['css']);
        $this->assertStringContainsString('data-mh-design=', $handoff);
        $this->assertStringNotContainsString('<style', $handoff);
        $this->assertSame(hash('sha256', trim($handoff)), $layout['source_html_digest']);
        $this->assertSame(hash('sha256', $layout['css']), $layout['css_digest']);
        $this->assertSame($response->json('layout.digest'), $response->json('handoff.layout_digest'));
        $marked = (new DesignMarker)->annotate($handoff, 'page-home.html');
        $coverage = (new TargetCoverageEvaluator)->evaluate($layout['ast'], $marked['bindings']);
        $this->assertTrue($coverage['passed'], implode(', ', $coverage['unmatched_rule_ids']));
        Http::assertNothingSent();
    }

    public function test_v11_rejects_the_legacy_v1_ast_instead_of_returning_a_layoutless_handoff(): void
    {
        $payload = $this->payload();
        $payload['contract_version'] = '1.1';

        $this->withToken('test-token')
            ->postJson('/api/v1/wireframes/materialize', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('contract_version', '1.1')
            ->assertJsonValidationErrors('wireframe_ast.version');
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

    public function test_v2_rejects_text_nodes_that_declare_image_identity(): void
    {
        $payload = $this->v2Payload();
        $payload['wireframe_ast']['pages'][0]['root']['children'][1]['children'][0]['children'][1] = WireframeV2Fixture::text(
            'hero-image',
            'body',
            '静かな診察室を想起させる導入ビジュアル',
        );

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
