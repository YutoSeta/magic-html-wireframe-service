<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidWireframeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateWireframeRequest;
use App\Http\Resources\WireframeResource;
use App\Services\Contracts\WireframeGenerator;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class WireframeController extends Controller
{
    public function store(
        GenerateWireframeRequest $request,
        WireframeGenerator $generator,
    ): WireframeResource|JsonResponse {
        try {
            $wireframe = $generator->generate(
                $request->validated('site_ast'),
                $request->validated('brief'),
                $request->validated('locale'),
            );
        } catch (InvalidWireframeException $exception) {
            return Problem::response($request, 422, 'invalid_wireframe', $exception->getMessage());
        } catch (RuntimeException $exception) {
            return Problem::response($request, 502, 'wireframe_provider_failed', $exception->getMessage());
        }

        return new WireframeResource($wireframe);
    }
}
