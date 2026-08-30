<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidWireframeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MaterializeWireframeRequest;
use App\Http\Resources\MaterializedWireframeResource;
use App\Services\WireframeMaterializer;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;

final class MaterializeWireframeController extends Controller
{
    public function __invoke(MaterializeWireframeRequest $request, WireframeMaterializer $materializer): JsonResponse
    {
        try {
            $result = $materializer->materialize($request->validated('wireframe_ast'));
        } catch (InvalidWireframeException $exception) {
            return Problem::response($request, 422, 'invalid_wireframe', $exception->getMessage());
        }

        return (new MaterializedWireframeResource($result))->response()->withHeaders([
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
