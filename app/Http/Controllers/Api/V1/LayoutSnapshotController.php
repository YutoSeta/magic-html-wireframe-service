<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidLayoutSnapshotException;
use App\Exceptions\InvalidWireframeException;
use App\Exceptions\LayoutSnapshotNotFoundException;
use App\Exceptions\LayoutSnapshotStoreException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FreezeLayoutSnapshotRequest;
use App\Http\Requests\PatchLayoutSnapshotRequest;
use App\Http\Requests\StoreLayoutSnapshotRequest;
use App\Http\Resources\LayoutSnapshotResource;
use App\Layout\LayoutSnapshotBuilder;
use App\Layout\LayoutSnapshotFreezer;
use App\Layout\LayoutSnapshotPatcher;
use App\Layout\LayoutSnapshotStore;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LayoutSnapshotController extends Controller
{
    public function store(
        StoreLayoutSnapshotRequest $request,
        LayoutSnapshotBuilder $builder,
        LayoutSnapshotStore $snapshots,
    ): JsonResponse {
        try {
            $snapshot = $builder->build(
                $request->validated('wireframe_ast'),
                $request->validated('validation_viewports'),
            );
            $record = $snapshots->put($snapshot);
        } catch (InvalidWireframeException $exception) {
            return Problem::response($request, 422, 'invalid_wireframe', $exception->getMessage());
        } catch (InvalidLayoutSnapshotException $exception) {
            return Problem::response($request, 422, 'invalid_layout_snapshot', $exception->getMessage());
        } catch (LayoutSnapshotStoreException $exception) {
            return Problem::response($request, 503, 'layout_snapshot_store_unavailable', $exception->getMessage());
        }

        return (new LayoutSnapshotResource($record))->response()
            ->setStatusCode($record['created'] ? 201 : 200)
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'ETag' => '"'.$snapshot['snapshot_digest'].'"',
                'Location' => route('layout-snapshots.show', ['layoutSnapshot' => $snapshot['snapshot_id']]),
            ]);
    }

    public function show(Request $request, string $layoutSnapshot, LayoutSnapshotStore $snapshots): JsonResponse
    {
        try {
            $record = $snapshots->find($layoutSnapshot);
        } catch (InvalidLayoutSnapshotException|LayoutSnapshotStoreException $exception) {
            return Problem::response($request, 503, 'layout_snapshot_store_unavailable', $exception->getMessage());
        }
        if ($record === null) {
            return Problem::response($request, 404, 'layout_snapshot_not_found', 'The Layout Snapshot does not exist or has expired.');
        }

        return (new LayoutSnapshotResource($record))->response()->withHeaders([
            'Cache-Control' => 'private, immutable',
            'ETag' => '"'.$record['snapshot']['snapshot_digest'].'"',
        ]);
    }

    public function freeze(
        FreezeLayoutSnapshotRequest $request,
        string $layoutSnapshot,
        LayoutSnapshotFreezer $freezer,
    ): JsonResponse {
        try {
            $record = $freezer->freeze(
                $layoutSnapshot,
                $request->validated('candidate_snapshot_digest'),
                $request->validated('validation'),
            );
        } catch (LayoutSnapshotNotFoundException $exception) {
            return Problem::response($request, 404, 'layout_snapshot_not_found', $exception->getMessage());
        } catch (InvalidLayoutSnapshotException $exception) {
            return Problem::response($request, 422, 'invalid_layout_snapshot_attestation', $exception->getMessage());
        } catch (LayoutSnapshotStoreException $exception) {
            return Problem::response($request, 503, 'layout_snapshot_store_unavailable', $exception->getMessage());
        }

        return (new LayoutSnapshotResource($record))->response()
            ->setStatusCode($record['created'] ? 201 : 200)
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'ETag' => '"'.$record['snapshot']['snapshot_digest'].'"',
                'Location' => route('layout-snapshots.show', ['layoutSnapshot' => $record['snapshot']['snapshot_id']]),
            ]);
    }

    public function patch(
        PatchLayoutSnapshotRequest $request,
        string $layoutSnapshot,
        LayoutSnapshotPatcher $patcher,
    ): JsonResponse {
        try {
            $record = $patcher->patch(
                $layoutSnapshot,
                $request->validated('page_key'),
                $request->validated('patch_plan'),
            );
        } catch (LayoutSnapshotNotFoundException $exception) {
            return Problem::response($request, 404, 'layout_snapshot_not_found', $exception->getMessage());
        } catch (InvalidLayoutSnapshotException $exception) {
            return Problem::response($request, 422, 'invalid_layout_snapshot_patch', $exception->getMessage());
        } catch (LayoutSnapshotStoreException $exception) {
            return Problem::response($request, 503, 'layout_snapshot_store_unavailable', $exception->getMessage());
        }

        return (new LayoutSnapshotResource($record))->response()
            ->setStatusCode($record['created'] ? 201 : 200)
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'ETag' => '"'.$record['snapshot']['snapshot_digest'].'"',
                'Location' => route('layout-snapshots.show', ['layoutSnapshot' => $record['snapshot']['snapshot_id']]),
            ]);
    }
}
