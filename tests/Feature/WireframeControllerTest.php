<?php

namespace Tests\Feature;

use App\Exceptions\InvalidWireframeException;
use App\Services\Contracts\WireframeGenerator;
use App\Services\WireframeValidator;
use Tests\TestCase;

final class WireframeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wireframe.service_token', 'test-token');
    }

    public function test_site_ast_is_converted_through_the_generator_boundary(): void
    {
        $this->app->instance(WireframeGenerator::class, new class implements WireframeGenerator
        {
            public function generate(array $siteAst, array $brief, string $locale): array
            {
                return [
                    'version' => 1,
                    'pages' => [[
                        'key' => $siteAst['pages'][0]['key'],
                        'sections' => [
                            ['key' => 'hero', 'composition' => 'hero', 'roles' => ['Title', 'Text', 'Actions', 'Image']],
                            ['key' => 'cta', 'composition' => 'cta', 'roles' => ['Title', 'Text', 'Actions']],
                        ],
                    ]],
                ];
            }
        });

        $this->withToken('test-token')->postJson('/api/v1/wireframes', $this->payload())
            ->assertOk()
            ->assertJsonPath('wireframe_ast.pages.0.key', 'home')
            ->assertJsonPath('wireframe_ast.pages.0.sections.0.composition', 'hero');
    }

    public function test_authentication_and_json_object_shape_are_enforced(): void
    {
        $this->postJson('/api/v1/wireframes', $this->payload())->assertUnauthorized();
        $payload = $this->payload();
        $payload['site_ast'] = [];
        $this->withToken('test-token')->postJson('/api/v1/wireframes', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('site_ast');
    }

    public function test_validator_rejects_page_mismatch_and_positional_design_shortcuts(): void
    {
        $this->expectException(InvalidWireframeException::class);
        app(WireframeValidator::class)->validate([
            'pages' => [[
                'key' => 'other',
                'sections' => [
                    ['key' => 'first', 'composition' => 'hero', 'roles' => ['Title']],
                    ['key' => 'second', 'composition' => 'cta', 'roles' => ['Actions']],
                ],
            ]],
        ], $this->payload()['site_ast']);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'contract_version' => '1.0',
            'site_ast' => [
                'version' => 1,
                'site' => ['name' => 'Web工房', 'description' => 'Description'],
                'pages' => [['key' => 'home', 'path' => '/', 'title' => 'Home', 'purpose' => 'Top']],
                'navigation' => [['label' => 'Home', 'path' => '/']],
            ],
            'brief' => [
                'organization' => 'Web工房',
                'goals' => '問い合わせ増加',
                'audience' => '中小企業',
                'tone' => '誠実',
                'requirements' => '5ページ',
                'materials' => [],
            ],
            'locale' => 'ja',
        ];
    }
}
