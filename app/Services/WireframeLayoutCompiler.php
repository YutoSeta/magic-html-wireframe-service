<?php

namespace App\Services;

use App\Support\CanonicalJson;
use YutoSeta\MagicHtmlLayout\LayoutAstCompiler;
use YutoSeta\MagicHtmlLayout\LayoutAstValidator;

final class WireframeLayoutCompiler
{
    public const PROFILE = 'approved-wireframe-layout-v1';

    /** @param array<string,mixed> $page @return array<string,mixed> */
    public function compile(array $page): array
    {
        $document = [
            'version' => 1,
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
                    'action-min' => '2.75rem',
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
                    'medium' => '45rem',
                    'wide' => '64rem',
                    'site' => '70rem',
                    'content' => '47.5rem',
                    'narrow' => '36rem',
                ],
            ],
            'rules' => $this->baseRules(),
        ];

        $this->collectNodeRules((array) $page['root'], $document['rules']);
        $validator = new LayoutAstValidator;
        $document = $validator->validate($document);
        $css = (new LayoutAstCompiler($validator))->compile($document);

        return [
            'profile' => self::PROFILE,
            'status' => 'frozen',
            'ast' => $document,
            'css' => $css,
            'ast_digest' => hash('sha256', CanonicalJson::encode($document)),
            'css_digest' => hash('sha256', $css),
            'compiler' => 'yutoseta/magic-html-layout-ast',
            'compiler_version' => '1.2',
            'responsive' => [
                'compact_max_px' => 719.98,
                'medium_min_px' => 720,
                'wide_min_px' => 1024,
            ],
        ];
    }

    public static function designKey(string $role, string $id, string $qualifier = ''): string
    {
        $identity = [$qualifier.$role];
        if ($role === 'Form') {
            $identity[] = 'm-form='.$id;
        }
        $identity[] = 'id='.$id;

        return 'd_'.substr(hash('sha256', implode('|', $identity)), 0, 12);
    }

    /** @return list<array<string,mixed>> */
    private function baseRules(): array
    {
        return [
            $this->rule('wireframe.page', 'global', ['role' => 'Page'], [
                'layout' => ['width' => 'container'],
            ]),
            $this->rule('wireframe.section', 'vocabulary', ['role' => 'Section'], [
                'spacing' => [
                    'paddingBlock' => 'section-block',
                    'paddingInline' => 'section-inline',
                    'marginBlock' => 'section-margin',
                ],
            ]),
            $this->rule('wireframe.header', 'vocabulary', ['role' => 'Header'], [
                'layout' => ['align' => 'center', 'distribute' => 'between'],
                'spacing' => ['paddingBlock' => 'chrome', 'paddingInline' => 'chrome'],
            ]),
            $this->rule('wireframe.navigation', 'vocabulary', ['role' => 'Group', 'qualifier' => 'Navigation'], [
                'layout' => ['align' => 'center', 'distribute' => 'end', 'width' => 'fit'],
            ], ['compact' => ['layout' => ['width' => 'full']]]),
            $this->rule('wireframe.footer', 'vocabulary', ['role' => 'Footer'], [
                'spacing' => ['paddingBlock' => 'chrome', 'paddingInline' => 'chrome'],
            ]),
            $this->rule('wireframe.action-link', 'vocabulary', ['role' => 'Link'], [
                'layout' => ['width' => 'fit', 'minHeight' => 'action-min'],
                'spacing' => ['paddingBlock' => 'action-block', 'paddingInline' => 'action-inline'],
            ], ['compact' => ['layout' => ['width' => 'full']]]),
            $this->rule('wireframe.action-button', 'vocabulary', ['role' => 'Btn'], [
                'layout' => ['width' => 'fit', 'minHeight' => 'action-min'],
                'spacing' => ['paddingBlock' => 'action-block', 'paddingInline' => 'action-inline'],
            ], ['compact' => ['layout' => ['width' => 'full']]]),
            $this->rule('wireframe.input-text', 'vocabulary', ['role' => 'Input', 'qualifier' => 'Text'], [
                'layout' => ['width' => 'full', 'minHeight' => 'control-min'],
                'spacing' => ['paddingBlock' => 'control-block', 'paddingInline' => 'control-inline'],
            ]),
            $this->rule('wireframe.input-textarea', 'vocabulary', ['role' => 'Input', 'qualifier' => 'Textarea'], [
                'layout' => ['width' => 'full', 'minHeight' => 'textarea-min'],
                'spacing' => ['paddingBlock' => 'control-block', 'paddingInline' => 'control-inline'],
            ]),
            $this->rule('wireframe.input-select', 'vocabulary', ['role' => 'Input', 'qualifier' => 'Select'], [
                'layout' => ['width' => 'full', 'minHeight' => 'control-min'],
                'spacing' => ['paddingBlock' => 'control-block', 'paddingInline' => 'control-inline'],
            ]),
            $this->rule('wireframe.image', 'vocabulary', ['role' => 'Image'], [
                'layout' => ['width' => 'full'],
                'media' => ['fit' => 'contain'],
            ]),
        ];
    }

    /** @param array<string,mixed> $node @param list<array<string,mixed>> $rules */
    private function collectNodeRules(array $node, array &$rules): void
    {
        if (($node['type'] ?? null) === 'Region') {
            $role = $this->regionRole((string) $node['semantic']);
            $qualifier = $this->regionQualifier((string) $node['semantic']);
            [$style, $responsive] = $this->regionLayout((string) $node['layout'], (string) $node['semantic']);
            $rules[] = $this->rule(
                'wireframe.region.'.(string) $node['id'],
                'instance',
                ['designKey' => self::designKey($role, (string) $node['id'], $qualifier)],
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
            'wireframe.image.'.(string) $node['id'],
            'instance',
            ['designKey' => self::designKey('Image', (string) $node['id'])],
            [
                'layout' => ['minHeight' => 'image-min'],
                'media' => ['aspect' => 'aspect-'.$aspect],
            ],
            ['compact' => ['layout' => ['minHeight' => 'image-compact-min']]],
        );
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function regionLayout(string $layout, string $semantic): array
    {
        return match ($layout) {
            'cluster' => [
                ['layout' => ['flow' => 'row', 'gap' => 'cluster']],
                match ($semantic) {
                    'header' => ['compact' => ['layout' => ['flow' => 'stack']]],
                    'navigation' => ['compact' => ['layout' => ['template' => '1fr 1fr']]],
                    default => [],
                },
            ],
            'grid-2' => $this->gridLayout('1fr 1fr'),
            'grid-3' => $this->gridLayout('1fr 1fr 1fr'),
            'grid-4' => $this->gridLayout('1fr 1fr 1fr 1fr'),
            'split' => $this->gridLayout('1fr 1fr'),
            'split-wide-start' => $this->gridLayout('3fr 2fr'),
            'split-wide-end' => $this->gridLayout('2fr 3fr'),
            'centered' => [[
                'layout' => ['flow' => 'stack', 'gap' => 'grid', 'width' => 'content'],
            ], []],
            default => [[
                'layout' => ['flow' => 'stack', 'gap' => 'stack'],
            ], []],
        };
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function gridLayout(string $template): array
    {
        return [
            ['layout' => ['template' => $template, 'gap' => 'grid']],
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
