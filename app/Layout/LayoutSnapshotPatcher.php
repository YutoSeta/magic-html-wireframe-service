<?php

namespace App\Layout;

use App\Exceptions\InvalidLayoutSnapshotException;
use App\Exceptions\LayoutSnapshotNotFoundException;
use YutoSeta\MagicHtmlLayout\LayoutAstException;
use YutoSeta\MagicHtmlLayout\LayoutAstPatcher as PageLayoutAstPatcher;
use YutoSeta\MagicHtmlLayout\LayoutSnapshot;

final class LayoutSnapshotPatcher
{
    public function __construct(
        private readonly LayoutSnapshotStore $snapshots,
        private readonly LayoutSnapshot $snapshotContract,
        private readonly PageLayoutAstPatcher $layoutPatcher,
    ) {}

    /**
     * @param  array<string,mixed>  $patchPlan
     * @return array{snapshot:array<string,mixed>,storage:array<string,mixed>,created:bool}
     */
    public function patch(string $candidateId, string $pageKey, array $patchPlan): array
    {
        $record = $this->snapshots->find($candidateId);
        if ($record === null) {
            throw new LayoutSnapshotNotFoundException('The candidate Layout Snapshot does not exist or has expired.');
        }

        $candidate = $record['snapshot'];
        if (($candidate['snapshot_id'] ?? null) !== $candidateId
            || ($candidate['snapshot_digest'] ?? null) !== substr($candidateId, 3)) {
            throw new InvalidLayoutSnapshotException('The candidate path and Snapshot digest do not identify the same artifact.');
        }
        if (($candidate['status'] ?? null) !== 'candidate'
            || ($candidate['validation']['status'] ?? null) !== 'pending') {
            throw new InvalidLayoutSnapshotException('Only a candidate with pending browser validation can receive a finite Layout patch.');
        }

        $pageIndex = $this->pageIndex($candidate, $pageKey);
        $baseAstDigest = (string) $candidate['pages'][$pageIndex]['layout']['ast_digest'];
        if (! hash_equals($baseAstDigest, (string) ($patchPlan['base_ast_digest'] ?? ''))) {
            throw new InvalidLayoutSnapshotException('The patch plan does not identify the selected page Layout AST.');
        }

        try {
            $patchedPage = $this->layoutPatcher->apply(
                $candidate['pages'][$pageIndex]['layout']['ast'],
                $patchPlan,
            );
            $normalizedPlan = $patchedPage['patch_plan'];
            $pages = $candidate['pages'];
            $pages[$pageIndex]['layout'] = ['ast' => $patchedPage['ast']];
            $pages = $this->propagateConstraintPatches($pages, $pageIndex, $normalizedPlan);
            $snapshot = $this->snapshotContract->create([
                'status' => 'candidate',
                'source_structure_digest' => $candidate['source_structure_digest'],
                'constraints' => $patchedPage['ast']['constraints'],
                'pages' => $pages,
                'validation' => LayoutSnapshotBuilder::pendingValidation($pages),
                'lineage' => $this->lineage($candidate, $pageKey, $normalizedPlan, $patchedPage['operations']),
            ]);
        } catch (LayoutAstException $exception) {
            throw new InvalidLayoutSnapshotException(
                'The finite Layout patch is invalid: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $this->assertSemanticArtifactsUnchanged($candidate, $snapshot);
        if (hash_equals((string) $candidate['snapshot_digest'], (string) $snapshot['snapshot_digest'])) {
            throw new InvalidLayoutSnapshotException('The finite Layout patch did not create a new candidate artifact.');
        }

        return $this->snapshots->put($snapshot);
    }

    /** @param array<string,mixed> $snapshot */
    private function pageIndex(array $snapshot, string $pageKey): int
    {
        $indexes = [];
        foreach ($snapshot['pages'] as $index => $page) {
            if (($page['page_key'] ?? null) === $pageKey) {
                $indexes[] = $index;
            }
        }
        if (count($indexes) !== 1) {
            throw new InvalidLayoutSnapshotException('The selected page does not exist exactly once in the candidate Layout Snapshot.');
        }

        return $indexes[0];
    }

    /**
     * A minimum action height is a site constraint. Apply the same finite
     * constraint issues to every other page so all page ASTs retain the exact
     * root constraint object required by the site-level Snapshot contract.
     *
     * @param  list<array<string,mixed>>  $pages
     * @param  array<string,mixed>  $normalizedPlan
     * @return list<array<string,mixed>>
     */
    private function propagateConstraintPatches(array $pages, int $selectedPageIndex, array $normalizedPlan): array
    {
        $issues = array_values(array_filter(
            $normalizedPlan['issues'],
            static fn (array $issue): bool => $issue['target_id'] === '$constraints',
        ));
        if ($issues === []) {
            return $pages;
        }

        foreach ($pages as $index => $page) {
            if ($index === $selectedPageIndex) {
                continue;
            }
            $result = $this->layoutPatcher->apply($page['layout']['ast'], [
                'contract_version' => '1.0',
                'stage' => 'layout',
                'base_ast_digest' => $page['layout']['ast_digest'],
                'issues' => $issues,
            ]);
            $pages[$index]['layout'] = ['ast' => $result['ast']];
        }

        return $pages;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $patchPlan
     * @param  list<array<string,mixed>>  $audit
     * @return array<string,mixed>
     */
    private function lineage(array $candidate, string $pageKey, array $patchPlan, array $audit): array
    {
        return [
            'parent_snapshot_id' => $candidate['snapshot_id'],
            'parent_snapshot_digest' => $candidate['snapshot_digest'],
            'delta' => [
                'profile' => 'layout-snapshot-delta-v1',
                'change_id' => 'layout-patch-'.LayoutSnapshot::digestValue($patchPlan),
                'reason' => "Apply browser-reviewed finite Layout corrections for page {$pageKey}.",
                'patch_plan' => $patchPlan,
                'operations' => array_map(
                    static function (array $operation, int $index) use ($pageKey, $patchPlan): array {
                        $isConstraint = $patchPlan['issues'][$index]['target_id'] === '$constraints';

                        return [
                            'sequence' => $operation['sequence'],
                            'operation' => 'apply_finite_layout_patch',
                            'target' => $isConstraint
                                ? ['kind' => 'constraints', 'page_key' => null]
                                : ['kind' => 'page', 'page_key' => $pageKey],
                            'before_digest' => $isConstraint
                                ? $operation['before_constraints_digest']
                                : $operation['before_ast_digest'],
                            'after_digest' => $isConstraint
                                ? $operation['after_constraints_digest']
                                : $operation['after_ast_digest'],
                            'summary' => $patchPlan['issues'][$index]['problem'].': '.$patchPlan['issues'][$index]['operation']['type'],
                            'finite_patch' => $patchPlan['issues'][$index],
                        ];
                    },
                    $audit,
                    array_keys($audit),
                ),
            ],
        ];
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $patched */
    private function assertSemanticArtifactsUnchanged(array $candidate, array $patched): void
    {
        $semanticArtifacts = static fn (array $snapshot): array => array_map(static fn (array $page): array => [
            'page_key' => $page['page_key'],
            'route' => $page['route'],
            'path' => $page['path'],
            'title' => $page['title'],
            'source_html' => $page['source_html'],
        ], $snapshot['pages']);

        if ($candidate['source_structure_digest'] !== $patched['source_structure_digest']
            || $semanticArtifacts($candidate) !== $semanticArtifacts($patched)) {
            throw new InvalidLayoutSnapshotException('A finite Layout patch changed semantic HTML or page identity.');
        }
    }
}
