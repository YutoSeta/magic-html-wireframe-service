<?php

namespace App\Layout;

use App\Exceptions\InvalidLayoutSnapshotException;
use YutoSeta\MagicHtmlDesign\DesignMarker;
use YutoSeta\MagicHtmlDesign\Stage\TargetCoverageEvaluator;
use YutoSeta\MagicHtmlLayout\LayoutAstException;
use YutoSeta\MagicHtmlLayout\LayoutSnapshot;
use YutoSeta\MagicHtmlLayout\LayoutStageGuard;

final class LayoutSnapshotVerifier
{
    public function __construct(
        private readonly TargetCoverageEvaluator $coverageEvaluator,
        private readonly LayoutSnapshot $snapshotContract,
    ) {}

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function verify(array $snapshot): array
    {
        try {
            $snapshot = $this->snapshotContract->verify($snapshot);
        } catch (LayoutAstException $exception) {
            throw new InvalidLayoutSnapshotException(
                'The Layout Snapshot contract is invalid: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $problems = [];
        foreach ($snapshot['pages'] as $index => $page) {
            $ast = $page['layout']['ast'];
            $css = $page['layout']['css'];
            if (($ast['version'] ?? null) !== 2) {
                $problems[] = "snapshot.pages.{$index}.layout.ast must be version 2.";
            }
            if (($page['layout']['compiler_version'] ?? null) !== '2.0') {
                $problems[] = "snapshot.pages.{$index}.layout.compiler_version must be 2.0.";
            }
            $geometryViolations = LayoutStageGuard::geometryViolations($css);
            if ($geometryViolations !== []) {
                $problems[] = "snapshot.pages.{$index}.layout.css contains paint, decor, or motion declarations: ".implode(', ', $geometryViolations).'.';
            }

            $html = base64_decode($page['source_html']['content_base64'], true);
            if (! is_string($html)) {
                $problems[] = "snapshot.pages.{$index}.source_html cannot be decoded.";

                continue;
            }
            $marked = (new DesignMarker)->annotate($html, $page['path']);
            $coverage = $this->coverageEvaluator->evaluate($ast, $marked['bindings']);
            if (! $coverage['passed']) {
                $problems[] = "snapshot.pages.{$index}.layout has targets absent from source_html: ".implode(', ', $coverage['unmatched_rule_ids']).'.';
            }
        }

        if ($problems !== []) {
            throw InvalidLayoutSnapshotException::fromProblems($problems);
        }

        return $snapshot;
    }
}
