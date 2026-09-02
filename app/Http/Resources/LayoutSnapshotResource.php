<?php

namespace App\Http\Resources;

use App\Layout\LayoutSnapshotBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LayoutSnapshotResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'contract_version' => '1.0',
            'layout_snapshot' => $this->resource['snapshot'],
            'layout_snapshot_references' => LayoutSnapshotBuilder::references($this->resource['snapshot']),
            'storage' => $this->resource['storage'],
        ];
    }
}
