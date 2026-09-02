<?php

namespace App\Layout;

use App\Exceptions\InvalidLayoutSnapshotException;
use App\Services\WireframeValidator;
use YutoSeta\MagicHtmlDesign\DesignMarker;
use YutoSeta\MagicHtmlDesign\Stage\TargetCoverageEvaluator;
use YutoSeta\MagicHtmlLayout\LayoutAstException;
use YutoSeta\MagicHtmlLayout\LayoutSnapshot;

final class LayoutSnapshotBuilder
{
    public const PROFILE = 'layout-snapshot-v1';

    public const LAYOUT_PROFILE = 'geometry-layout-v2';

    public const REFERENCE_PROFILE = 'layout-snapshot-reference-v1';

    public function __construct(
        private readonly WireframeValidator $wireframeValidator,
        private readonly CommonLayoutCompiler $layoutCompiler,
        private readonly SemanticLayoutHtmlRenderer $htmlRenderer,
        private readonly TargetCoverageEvaluator $coverageEvaluator,
        private readonly LayoutSnapshot $snapshotContract,
    ) {}

    /**
     * @param  array<string,mixed>  $wireframe
     * @param  list<int>  $validationViewports
     * @return array<string,mixed>
     */
    public function build(array $wireframe, array $validationViewports): array
    {
        $pages = is_array($wireframe['pages'] ?? null) ? $wireframe['pages'] : [];
        $siteAst = ['pages' => array_map(static fn (mixed $page): array => [
            'key' => is_array($page) ? ($page['key'] ?? null) : null,
            'path' => is_array($page) ? ($page['path'] ?? null) : null,
            'title' => is_array($page) ? ($page['title'] ?? null) : null,
        ], $pages)];
        $normalized = $this->wireframeValidator->validate($wireframe, $siteAst, 2);
        $constraints = $this->constraints($validationViewports);

        $compiledLayouts = [];
        foreach ($normalized['pages'] as $page) {
            $compiledLayouts[(string) $page['key']] = $this->layoutCompiler->compile($page, $constraints);
        }
        $renderedPages = $this->htmlRenderer->render(
            $normalized['pages'],
            (string) $normalized['locale'],
            array_map(static fn (array $layout): string => (string) $layout['ast_digest'], $compiledLayouts),
        );

        $snapshotPages = [];
        foreach ($normalized['pages'] as $page) {
            $pageKey = (string) $page['key'];
            $rendered = $renderedPages[$pageKey];
            $html = $rendered['html'];
            $compiled = $compiledLayouts[$pageKey];
            $marked = (new DesignMarker)->annotate($html, $rendered['path']);
            $coverage = $this->coverageEvaluator->evaluate($compiled['ast'], $marked['bindings']);
            if (! $coverage['passed']) {
                throw InvalidLayoutSnapshotException::fromProblems([
                    'The compiled Layout has targets absent from its semantic HTML: '.implode(', ', $coverage['unmatched_rule_ids']).'.',
                ]);
            }
            $snapshotPages[] = [
                'page_key' => $pageKey,
                'route' => (string) $page['path'],
                'path' => $rendered['path'],
                'title' => $rendered['title'],
                'source_html' => [
                    'mime' => 'text/html; charset=UTF-8',
                    'content_base64' => base64_encode($html),
                    'sha256' => hash('sha256', $html),
                ],
                'layout' => [
                    'ast' => $compiled['ast'],
                    'css' => $compiled['css'],
                    'ast_digest' => $compiled['ast_digest'],
                    'css_digest' => $compiled['css_digest'],
                ],
            ];
        }

        try {
            return $this->snapshotContract->create([
                'status' => 'candidate',
                'source_structure_digest' => LayoutSnapshot::digestValue($normalized),
                'constraints' => $constraints,
                'pages' => $snapshotPages,
                'validation' => self::pendingValidation($snapshotPages),
                'lineage' => null,
            ]);
        } catch (LayoutAstException $exception) {
            throw new InvalidLayoutSnapshotException(
                'The common Layout could not be frozen: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,string>> */
    public static function references(array $snapshot): array
    {
        return array_map(static fn (array $page): array => [
            'profile' => self::REFERENCE_PROFILE,
            'snapshot_id' => (string) $snapshot['snapshot_id'],
            'snapshot_digest' => (string) $snapshot['snapshot_digest'],
            'page_key' => (string) $page['page_key'],
            'source_html_digest' => (string) $page['source_html']['sha256'],
            'layout_ast_digest' => (string) $page['layout']['ast_digest'],
            'layout_css_digest' => (string) $page['layout']['css_digest'],
        ], $snapshot['pages']);
    }

    /** @param list<int> $validationViewports @return array<string,mixed> */
    private function constraints(array $validationViewports): array
    {
        sort($validationViewports, SORT_NUMERIC);

        return [
            'container_max_width_px' => (int) config('wireframe.layout.container_max_width_px', 1120),
            'content_max_width_px' => (int) config('wireframe.layout.content_max_width_px', 760),
            'responsive' => [
                'compact_max_px' => (float) config('wireframe.layout.responsive.compact_max_px', 719.98),
                'medium_min_px' => (int) config('wireframe.layout.responsive.medium_min_px', 720),
                'wide_min_px' => (int) config('wireframe.layout.responsive.wide_min_px', 1024),
            ],
            'validation_viewports_px' => array_values($validationViewports),
            'minimum_action_height_px' => (int) config('wireframe.layout.minimum_action_height_px', 44),
            'mobile' => [
                'reflow' => 'single-column',
                'preserve_source_order' => true,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $pages
     * @return array<string,mixed>
     */
    public static function pendingValidation(array $pages): array
    {
        $viewportHeights = (array) config('wireframe.layout.validation_viewport_heights_px', [
            390 => 844,
            768 => 1024,
            1440 => 900,
        ]);

        return [
            'status' => 'pending',
            'validator' => 'magic-html-preview-service',
            'validator_version' => '1.0',
            'subject' => null,
            'pages' => array_map(static fn (array $page): array => [
                'page_key' => $page['page_key'],
                'route' => $page['route'],
                'path' => $page['path'],
                'viewports' => array_map(static fn (int $width): array => [
                    'width_px' => $width,
                    'height_px' => (int) ($viewportHeights[$width] ?? 900),
                    'breakpoint' => match ($width) {
                        390 => 'compact',
                        768 => 'medium',
                        default => 'wide',
                    },
                    'breakpoint_outcome' => 'pending',
                    'geometry_outcome' => 'pending',
                    'measurements' => null,
                    'issues' => [],
                ], [390, 768, 1440]),
            ], $pages),
        ];
    }
}
