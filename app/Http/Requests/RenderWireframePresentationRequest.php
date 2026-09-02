<?php

namespace App\Http\Requests;

final class RenderWireframePresentationRequest extends ContractRequest
{
    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'layout_snapshot_ref' => ['required', 'array:profile,snapshot_id,snapshot_digest,page_key,source_html_digest,layout_ast_digest,layout_css_digest'],
            'layout_snapshot_ref.profile' => ['required', 'in:layout-snapshot-reference-v1'],
            'layout_snapshot_ref.snapshot_id' => ['required', 'regex:/\Als_[a-f0-9]{64}\z/D'],
            'layout_snapshot_ref.snapshot_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'layout_snapshot_ref.page_key' => ['required', 'regex:/\A[a-z0-9][a-z0-9-]{0,99}\z/D'],
            'layout_snapshot_ref.source_html_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'layout_snapshot_ref.layout_ast_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'layout_snapshot_ref.layout_css_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
        ];
    }
}
