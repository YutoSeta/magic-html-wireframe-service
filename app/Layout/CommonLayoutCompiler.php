<?php

namespace App\Layout;

use App\Support\CanonicalJson;
use YutoSeta\MagicHtmlLayout\LayoutAstCompiler;
use YutoSeta\MagicHtmlLayout\LayoutAstValidator;
use YutoSeta\MagicHtmlLayout\LayoutStageGuard;

final class CommonLayoutCompiler
{
    public function __construct(private readonly LayoutAstValidator $validator) {}

    /**
     * @param  array<string,mixed>  $page
     * @param  array<string,mixed>  $constraints
     * @return array<string,mixed>
     */
    public function compile(array $page, array $constraints): array
    {
        $document = [
            'version' => 2,
            'constraints' => $constraints,
            'tokens' => [
                'space' => [
                    'node' => '0.125rem',
                    'leaf' => '0.375rem',
                    'stack' => '0.625rem',
                    'cluster' => '0.625rem',
                    'grid' => '0.875rem',
                    'section-block' => 'clamp(1.5rem, 5vw, 3rem)',
                    'section-inline' => 'clamp(0.875rem, 4vw, 3rem)',
                    'section-margin' => '1rem',
                    'chrome' => '1rem',
                    'control-block' => '0.625rem',
                    'control-inline' => '0.625rem',
                    'action-block' => '0.625rem',
                    'action-inline' => '1.125rem',
                ],
                'size' => [
                    'control-min' => '2.75rem',
                    'textarea-min' => '8rem',
                    'image-min' => '13.75rem',
                    'image-compact-min' => '8.75rem',
                    'aspect-16-9' => '16 / 9',
                    'aspect-4-3' => '4 / 3',
                    'aspect-3-2' => '3 / 2',
                    'aspect-1-1' => '1 / 1',
                    'aspect-2-3' => '2 / 3',
                ],
                'container' => [
                    'narrow' => '36rem',
                ],
            ],
            'rules' => $this->baseRules($page),
        ];

        $this->collectNodeRules((array) $page['root'], $document['rules']);
        $document = $this->validator->validateV2($document);
        $css = (new LayoutAstCompiler($this->validator))->compileV2($document);
        LayoutStageGuard::assertGeometry($css);

        return [
            'profile' => LayoutSnapshotBuilder::LAYOUT_PROFILE,
            'ast' => $document,
            'css' => $css,
            'ast_digest' => hash('sha256', CanonicalJson::encode($document)),
            'css_digest' => hash('sha256', $css),
            'compiler' => 'yutoseta/magic-html-layout-ast',
            'compiler_version' => LayoutAstCompiler::OUTPUT_VERSION_V2,
        ];
    }

    /** @param array<string,mixed> $page @return list<array<string,mixed>> */
    private function baseRules(array $page): array
    {
        $inventory = [];
        $this->collectInventory((array) $page['root'], $inventory);
        $rules = [
            $this->rule('layout.page', 'global', ['role' => 'Page'], [
                'layout' => ['width' => 'container'],
            ]),
            $this->rule('layout.section', 'vocabulary', ['role' => 'Section'], [
                'spacing' => [
                    'paddingBlock' => 'section-block',
                    'paddingInline' => 'section-inline',
                    'marginBlock' => 'section-margin',
                ],
            ]),
            $this->rule('layout.header', 'vocabulary', ['role' => 'Header'], [
                'layout' => ['align' => 'center', 'distribute' => 'between'],
                'spacing' => ['paddingBlock' => 'chrome', 'paddingInline' => 'chrome'],
            ]),
            $this->rule('layout.navigation', 'vocabulary', ['role' => 'Group', 'qualifier' => 'Navigation'], [
                'layout' => ['align' => 'center', 'distribute' => 'end', 'width' => 'fit'],
            ], ['compact' => ['layout' => ['width' => 'full']]]),
            $this->rule('layout.footer', 'vocabulary', ['role' => 'Footer'], [
                'spacing' => ['paddingBlock' => 'chrome', 'paddingInline' => 'chrome'],
            ]),
            $this->rule('layout.action-link', 'vocabulary', ['role' => 'Link'], [
                'layout' => ['width' => 'fit', 'minHeight' => 'action-min'],
                'spacing' => ['paddingBlock' => 'action-block', 'paddingInline' => 'action-inline'],
            ], ['compact' => ['layout' => ['width' => 'full']]]),
            $this->rule('layout.action-button', 'vocabulary', ['role' => 'Btn'], [
                'layout' => ['width' => 'fit', 'minHeight' => 'action-min'],
                'spacing' => ['paddingBlock' => 'action-block', 'paddingInline' => 'action-inline'],
            ], ['compact' => ['layout' => ['width' => 'full']]]),
            $this->rule('layout.input-text', 'vocabulary', ['role' => 'Input', 'qualifier' => 'Text'], [
                'layout' => ['width' => 'full', 'minHeight' => 'control-min'],
                'spacing' => ['paddingBlock' => 'control-block', 'paddingInline' => 'control-inline'],
            ]),
            $this->rule('layout.input-textarea', 'vocabulary', ['role' => 'Input', 'qualifier' => 'Textarea'], [
                'layout' => ['width' => 'full', 'minHeight' => 'textarea-min'],
                'spacing' => ['paddingBlock' => 'control-block', 'paddingInline' => 'control-inline'],
            ]),
            $this->rule('layout.input-select', 'vocabulary', ['role' => 'Input', 'qualifier' => 'Select'], [
                'layout' => ['width' => 'full', 'minHeight' => 'control-min'],
                'spacing' => ['paddingBlock' => 'control-block', 'paddingInline' => 'control-inline'],
            ]),
            $this->rule('layout.image', 'vocabulary', ['role' => 'Image'], [
                'layout' => ['width' => 'full'],
                'media' => ['fit' => 'contain'],
            ]),
        ];
        $requirements = [
            'layout.header' => 'region:header',
            'layout.navigation' => 'region:navigation',
            'layout.footer' => 'region:footer',
            'layout.action-link' => 'node:Link',
            'layout.action-button' => 'node:Button',
            'layout.input-text' => 'node:Input',
            'layout.input-textarea' => 'node:Textarea',
            'layout.input-select' => 'node:Select',
            'layout.image' => 'node:Image',
        ];

        return array_values(array_filter(
            $rules,
            static fn (array $rule): bool => ! isset($requirements[$rule['id']])
                || isset($inventory[$requirements[$rule['id']]]),
        ));
    }

    /** @param array<string,mixed> $node @param array<string,true> $inventory */
    private function collectInventory(array $node, array &$inventory): void
    {
        $type = (string) ($node['type'] ?? '');
        $inventory['node:'.$type] = true;
        if ($type !== 'Region') {
            return;
        }
        $inventory['region:'.(string) ($node['semantic'] ?? '')] = true;
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectInventory($child, $inventory);
            }
        }
    }

    /** @param array<string,mixed> $node @param list<array<string,mixed>> $rules */
    private function collectNodeRules(array $node, array &$rules): void
    {
        if (($node['type'] ?? null) === 'Region') {
            $role = $this->regionRole((string) $node['semantic']);
            $qualifier = $this->regionQualifier((string) $node['semantic']);
            [$style, $responsive] = $this->regionLayout((string) $node['layout']);
            $rules[] = $this->rule(
                'layout.region.'.(string) $node['id'],
                'instance',
                ['designKey' => LayoutDesignKey::forNode($role, (string) $node['id'], $qualifier)],
                $style,
                $responsive,
            );
            foreach ((array) $node['children'] as $child) {
                if (is_array($child)) {
                    $this->collectNodeRules($child, $rules);
                }
            }

            return;
        }

        if (($node['type'] ?? null) !== 'Image') {
            return;
        }

        $aspect = str_replace(':', '-', (string) $node['aspect']);
        $rules[] = $this->rule(
            'layout.image.'.(string) $node['id'],
            'instance',
            ['designKey' => LayoutDesignKey::forNode('Image', (string) $node['id'])],
            [
                'layout' => ['minHeight' => 'image-min'],
                'media' => ['aspect' => 'aspect-'.$aspect],
            ],
            ['compact' => ['layout' => ['minHeight' => 'image-compact-min']]],
        );
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function regionLayout(string $layout): array
    {
        return match ($layout) {
            'cluster' => [
                ['layout' => ['flow' => 'cluster', 'gap' => 'cluster']],
                ['compact' => ['layout' => ['flow' => 'stack']]],
            ],
            'grid-2' => $this->gridLayout('grid', '1fr 1fr'),
            'grid-3' => $this->gridLayout('grid', '1fr 1fr 1fr'),
            'grid-4' => $this->gridLayout('grid', '1fr 1fr 1fr 1fr'),
            'split' => $this->gridLayout('split', '1fr 1fr'),
            'split-wide-start' => $this->gridLayout('split', '3fr 2fr'),
            'split-wide-end' => $this->gridLayout('split', '2fr 3fr'),
            'centered' => [[
                'layout' => ['flow' => 'stack', 'gap' => 'grid', 'width' => 'content'],
            ], []],
            default => [[
                'layout' => ['flow' => 'stack', 'gap' => 'stack'],
            ], []],
        };
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function gridLayout(string $flow, string $template): array
    {
        return [
            ['layout' => ['flow' => $flow, 'template' => $template, 'gap' => 'grid']],
            ['compact' => ['layout' => ['flow' => 'stack']]],
        ];
    }

    /** @param array<string,string> $target @param array<string,mixed> $style @param array<string,mixed> $responsive @return array<string,mixed> */
    private function rule(string $id, string $layer, array $target, array $style, array $responsive = []): array
    {
        return array_filter([
            'id' => $id,
            'layer' => $layer,
            'target' => $target,
            'style' => $style,
            'responsive' => $responsive,
        ], static fn (mixed $value, string $key): bool => $key !== 'responsive' || $value !== [], ARRAY_FILTER_USE_BOTH);
    }

    private function regionRole(string $semantic): string
    {
        return match ($semantic) {
            'document' => 'Page',
            'header' => 'Header',
            'main' => 'Main',
            'section' => 'Section',
            'article', 'list-item' => 'Item',
            'aside' => 'Frame',
            'footer' => 'Footer',
            'form' => 'Form',
            default => 'Group',
        };
    }

    private function regionQualifier(string $semantic): string
    {
        return match ($semantic) {
            'navigation' => 'Navigation',
            default => '',
        };
    }
}
