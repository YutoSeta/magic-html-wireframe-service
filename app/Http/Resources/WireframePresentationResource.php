<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WireframePresentationResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'contract_version' => '1.0',
            'snapshot_id' => $this->resource['snapshot_id'],
            'entry_path' => $this->resource['entry_path'],
            'files' => $this->resource['files'],
            'file_manifest' => $this->resource['file_manifest'],
            'layout_snapshot_reference' => $this->resource['layout_snapshot_reference'],
            'presentation_snapshot' => $this->resource['presentation_snapshot'],
            'wireframe_skin_ast' => $this->resource['wireframe_skin_ast'],
            'wireframe_decor_ast' => $this->resource['wireframe_decor_ast'],
            'telemetry' => $this->resource['telemetry'],
        ];
    }
}
