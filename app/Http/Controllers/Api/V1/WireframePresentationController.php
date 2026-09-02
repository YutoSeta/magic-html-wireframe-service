<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidLayoutSnapshotException;
use App\Exceptions\LayoutSnapshotNotFoundException;
use App\Exceptions\LayoutSnapshotStoreException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RenderWireframePresentationRequest;
use App\Http\Resources\WireframePresentationResource;
use App\Support\Problem;
use App\WireframePresentation\WireframePresenter;
use Illuminate\Http\JsonResponse;

final class WireframePresentationController extends Controller
{
    public function __invoke(RenderWireframePresentationRequest $request, WireframePresenter $presenter): JsonResponse
    {
        try {
            $result = $presenter->present($request->validated('layout_snapshot_ref'));
        } catch (LayoutSnapshotNotFoundException $exception) {
            return Problem::response($request, 404, 'layout_snapshot_not_found', $exception->getMessage());
        } catch (InvalidLayoutSnapshotException $exception) {
            return Problem::response($request, 422, 'invalid_layout_snapshot_reference', $exception->getMessage());
        } catch (LayoutSnapshotStoreException $exception) {
            return Problem::response($request, 503, 'layout_snapshot_store_unavailable', $exception->getMessage());
        }

        return (new WireframePresentationResource($result))->response()->withHeaders([
            'Cache-Control' => 'private, no-store',
            'ETag' => '"'.$result['presentation_snapshot']['snapshot_digest'].'"',
        ]);
    }
}
