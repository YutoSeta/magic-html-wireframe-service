<?php

namespace App\WireframePresentation;

use App\Exceptions\InvalidLayoutSnapshotException;
use App\Exceptions\LayoutSnapshotNotFoundException;
use App\Layout\LayoutSnapshotBuilder;
use App\Layout\LayoutSnapshotStore;
use App\Support\CanonicalJson;

final class WireframePresenter
{
    public function __construct(
        private readonly LayoutSnapshotStore $snapshots,
        private readonly WireframePresentationPreset $preset,
    ) {}

    /** @param array<string,string> $reference @return array<string,mixed> */
    public function present(array $reference): array
    {
        $startedAt = hrtime(true);
        $record = $this->snapshots->find((string) ($reference['snapshot_id'] ?? ''));
        if ($record === null) {
            throw new LayoutSnapshotNotFoundException('The referenced Layout Snapshot does not exist or has expired.');
        }
        $snapshot = $record['snapshot'];
        $page = collect($snapshot['pages'])->firstWhere('page_key', $reference['page_key'] ?? null);
        if (! is_array($page)) {
            throw new InvalidLayoutSnapshotException('The referenced page does not exist in the Layout Snapshot.');
        }
        $expectedReference = collect(LayoutSnapshotBuilder::references($snapshot))
            ->firstWhere('page_key', $page['page_key']);
        if (! is_array($expectedReference)
            || ! hash_equals(CanonicalJson::encode($expectedReference), CanonicalJson::encode($reference))) {
            throw new InvalidLayoutSnapshotException('The Layout Snapshot reference digests do not match the stored page.');
        }

        $sourceHtml = base64_decode((string) $page['source_html']['content_base64'], true);
        if (! is_string($sourceHtml)) {
            throw new InvalidLayoutSnapshotException('The Layout Snapshot source HTML cannot be decoded.');
        }
        $sourceHtmlDigest = (string) $page['source_html']['sha256'];
        if (! hash_equals($sourceHtmlDigest, hash('sha256', $sourceHtml))) {
            throw new InvalidLayoutSnapshotException('The Layout Snapshot source HTML digest does not match its bytes.');
        }
        $presentationCss = $this->preset->css();
        $previewHtml = $this->injectPresentation(
            $sourceHtml,
            (string) $page['layout']['css'],
            $presentationCss,
            (string) $snapshot['snapshot_id'],
            (string) $snapshot['snapshot_digest'],
            $sourceHtmlDigest,
            (string) $page['layout']['ast_digest'],
            (string) $page['layout']['css_digest'],
        );
        if (! hash_equals($this->body($sourceHtml), $this->body($previewHtml))) {
            throw new \LogicException('The Wireframe Presenter changed the semantic body.');
        }

        $file = [
            'path' => (string) $page['path'],
            'mime' => 'text/html; charset=UTF-8',
            'content_base64' => base64_encode($previewHtml),
        ];
        $manifest = [
            'page_key' => (string) $page['page_key'],
            'path' => (string) $page['path'],
            'size' => strlen($previewHtml),
            'sha256' => hash('sha256', $previewHtml),
        ];
        $presentationPayload = [
            'version' => 1,
            'profile' => WireframePresentationPreset::PROFILE,
            'presenter_version' => WireframePresentationPreset::VERSION,
            'layout_snapshot_id' => (string) $snapshot['snapshot_id'],
            'layout_snapshot_digest' => (string) $snapshot['snapshot_digest'],
            'source_snapshot_status' => (string) $snapshot['status'],
            'source_validation_status' => (string) $snapshot['validation']['status'],
            'page_key' => (string) $page['page_key'],
            'source_html_digest' => (string) $page['source_html']['sha256'],
            'layout_ast_digest' => (string) $page['layout']['ast_digest'],
            'layout_css_digest' => (string) $page['layout']['css_digest'],
            'wireframe_presentation_css_digest' => hash('sha256', $presentationCss),
            'file_digest' => $manifest['sha256'],
        ];
        $presentationDigest = hash('sha256', CanonicalJson::encode($presentationPayload));

        return [
            'snapshot_id' => 'wps_'.$presentationDigest,
            'entry_path' => $file['path'],
            'files' => [$file],
            'file_manifest' => [$manifest],
            'layout_snapshot_reference' => $expectedReference,
            'presentation_snapshot' => [
                ...$presentationPayload,
                'snapshot_id' => 'wps_'.$presentationDigest,
                'snapshot_digest' => $presentationDigest,
            ],
            'wireframe_skin_ast' => $this->preset->skin(),
            'wireframe_decor_ast' => $this->preset->decor(),
            'telemetry' => [
                'operation' => 'wireframes.present',
                'renderer' => 'layout-snapshot-wireframe-presenter',
                'renderer_version' => WireframePresentationPreset::VERSION,
                'provider' => 'deterministic',
                'model' => null,
                'response_id' => null,
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'estimated_cost' => null,
                'provider_request_count' => 0,
                'render_duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            ],
        ];
    }

    private function injectPresentation(
        string $html,
        string $layoutCss,
        string $presentationCss,
        string $snapshotId,
        string $snapshotDigest,
        string $sourceHtmlDigest,
        string $layoutAstDigest,
        string $layoutCssDigest,
    ): string {
        if (substr_count(strtolower($html), '</head>') !== 1) {
            throw new InvalidLayoutSnapshotException('The Layout Snapshot source HTML must contain exactly one closing head element.');
        }
        $html = preg_replace(
            "/style-src 'none'/i",
            "style-src 'unsafe-inline'",
            $html,
            1,
            $replacementCount,
        ) ?? $html;
        if ($replacementCount !== 1) {
            throw new InvalidLayoutSnapshotException('The Layout Snapshot source HTML has an unsupported Content Security Policy.');
        }

        preg_match_all(
            '/<meta\b[^>]*\bname\s*=\s*(["\'])layout-ast-sha256\1[^>]*>/i',
            $html,
            $layoutDigestDeclarations,
            PREG_OFFSET_CAPTURE,
        );
        if (count($layoutDigestDeclarations[0]) !== 1) {
            throw new InvalidLayoutSnapshotException('The Layout Snapshot source HTML does not declare its Layout AST digest.');
        }
        [$layoutDigestDeclaration, $layoutDigestOffset] = $layoutDigestDeclarations[0][0];
        if (preg_match('/\A<meta name="layout-ast-sha256" content="[a-f0-9]{64}">\z/D', $layoutDigestDeclaration) !== 1) {
            throw new InvalidLayoutSnapshotException('The Layout Snapshot source HTML has an unsupported Layout AST digest declaration.');
        }
        $html = substr_replace(
            $html,
            '<meta name="layout-ast-sha256" content="'.$layoutAstDigest.'">',
            $layoutDigestOffset,
            strlen($layoutDigestDeclaration),
        );

        $head = '<meta name="layout-snapshot-id" content="'.$snapshotId.'">'."\n"
            .'<meta name="layout-snapshot-sha256" content="'.$snapshotDigest.'">'."\n"
            .'<meta name="layout-source-html-sha256" content="'.$sourceHtmlDigest.'">'."\n"
            .'<meta name="layout-css-sha256" content="'.$layoutCssDigest.'">'."\n"
            .'<meta name="wireframe-presentation-profile" content="'.WireframePresentationPreset::PROFILE.'">'."\n"
            .'<style data-layout-snapshot="'.$snapshotId.'">'."\n{$layoutCss}\n</style>\n"
            .'<style data-wireframe-presentation="'.WireframePresentationPreset::PROFILE.'">'."\n{$presentationCss}\n</style>\n";

        return preg_replace('/<\/head>/i', $head.'</head>', $html, 1) ?? $html;
    }

    private function body(string $html): string
    {
        if (preg_match('/<body(?:\s[^>]*)?>.*<\/body>/is', $html, $match) !== 1) {
            throw new InvalidLayoutSnapshotException('The Layout Snapshot source HTML must contain a body element.');
        }

        return $match[0];
    }
}
