<?php

namespace App\Layout;

use App\Exceptions\InvalidLayoutSnapshotException;
use App\Exceptions\LayoutSnapshotNotFoundException;
use YutoSeta\MagicHtmlLayout\LayoutAstException;
use YutoSeta\MagicHtmlLayout\LayoutSnapshot;

final class LayoutSnapshotFreezer
{
    public function __construct(
        private readonly LayoutSnapshotStore $snapshots,
        private readonly LayoutSnapshot $snapshotContract,
    ) {}

    /**
     * @param  array<string,mixed>  $validation
     * @return array{snapshot:array<string,mixed>,storage:array<string,mixed>,created:bool}
     */
    public function freeze(string $candidateId, string $candidateDigest, array $validation): array
    {
        $record = $this->snapshots->find($candidateId);
        if ($record === null) {
            throw new LayoutSnapshotNotFoundException('The candidate Layout Snapshot does not exist or has expired.');
        }
        $candidate = $record['snapshot'];
        if (! hash_equals((string) $candidate['snapshot_digest'], $candidateDigest)) {
            throw new InvalidLayoutSnapshotException('The candidate path and digest do not identify the same Layout Snapshot.');
        }
        if (($candidate['status'] ?? null) !== 'candidate'
            || ($candidate['validation']['status'] ?? null) !== 'pending') {
            throw new InvalidLayoutSnapshotException('Only a candidate with pending browser validation can be frozen.');
        }
        if (($validation['subject']['candidate_snapshot_id'] ?? null) !== $candidateId
            || ($validation['subject']['candidate_snapshot_digest'] ?? null) !== $candidateDigest) {
            throw new InvalidLayoutSnapshotException('The browser validation subject does not match the candidate path and digest.');
        }

        try {
            $frozen = $this->snapshotContract->freeze(
                $candidate,
                $validation,
                'freeze-'.$candidate['snapshot_digest'],
                'Attach passed browser geometry validation without changing HTML or Layout.',
            );
        } catch (LayoutAstException $exception) {
            throw new InvalidLayoutSnapshotException(
                'The browser validation attestation cannot freeze this Layout: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $this->assertPageArtifactsUnchanged($candidate, $frozen);

        return $this->snapshots->put($frozen);
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $frozen */
    private function assertPageArtifactsUnchanged(array $candidate, array $frozen): void
    {
        $candidateArtifacts = array_map(static fn (array $page): array => [
            'page_key' => $page['page_key'],
            'route' => $page['route'],
            'path' => $page['path'],
            'source_html_digest' => $page['source_html']['sha256'],
            'layout_ast_digest' => $page['layout']['ast_digest'],
            'layout_css_digest' => $page['layout']['css_digest'],
        ], $candidate['pages']);
        $frozenArtifacts = array_map(static fn (array $page): array => [
            'page_key' => $page['page_key'],
            'route' => $page['route'],
            'path' => $page['path'],
            'source_html_digest' => $page['source_html']['sha256'],
            'layout_ast_digest' => $page['layout']['ast_digest'],
            'layout_css_digest' => $page['layout']['css_digest'],
        ], $frozen['pages']);

        if ($candidateArtifacts !== $frozenArtifacts) {
            throw new InvalidLayoutSnapshotException('Freezing changed source HTML or common Layout bytes.');
        }
    }
}
