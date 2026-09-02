<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MaterializedWireframeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'contract_version' => (string) $request->input('contract_version', '1.0'),
            'source_digest' => $this->resource['source_digest'],
            'entry_path' => $this->resource['entry_path'],
            'files' => $this->resource['files'],
            'file_manifest' => $this->resource['file_manifest'],
            ...(isset($this->resource['wireframe_decorate_ast'])
                ? ['wireframe_decorate_ast' => $this->resource['wireframe_decorate_ast']]
                : []),
            ...(isset($this->resource['wireframe_skin_ast'])
                ? ['wireframe_skin_ast' => $this->resource['wireframe_skin_ast']]
                : []),
            ...(isset($this->resource['wireframe_decor_ast'])
                ? ['wireframe_decor_ast' => $this->resource['wireframe_decor_ast']]
                : []),
            ...(isset($this->resource['layout'])
                ? ['layout' => $this->resource['layout']]
                : []),
            ...(isset($this->resource['handoff'])
                ? ['handoff' => $this->resource['handoff']]
                : []),
            'telemetry' => $this->resource['telemetry'],
        ];
    }
}
