<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

final class StoreLayoutSnapshotRequest extends ContractRequest
{
    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'wireframe_ast' => ['required', 'array'],
            'wireframe_ast.version' => ['required', 'integer', 'in:2'],
            'wireframe_ast.locale' => ['required', 'string', 'min:2', 'max:20'],
            'wireframe_ast.pages' => ['required', 'array', 'min:1', 'max:8'],
            'validation_viewports' => ['required', 'array', 'size:3'],
            'validation_viewports.*' => ['required', 'integer', 'distinct:strict'],
        ];
    }

    /** @return array<int,callable(Validator):void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $document = json_decode($this->getContent());
                if (! is_object($document?->wireframe_ast ?? null)) {
                    $validator->errors()->add('wireframe_ast', 'The Wireframe AST must be a JSON object.');

                    return;
                }
                $wireframe = (array) $document->wireframe_ast;
                $keys = array_keys($wireframe);
                sort($keys, SORT_STRING);
                if ($keys !== ['locale', 'pages', 'version']) {
                    $validator->errors()->add('wireframe_ast', 'The Wireframe AST contains unsupported top-level fields.');
                }
                $viewports = $this->input('validation_viewports');
                if (! is_array($viewports) || ! array_is_list($viewports)) {
                    $validator->errors()->add('validation_viewports', 'Validation viewports must be a JSON list.');

                    return;
                }
                if ($viewports !== [390, 768, 1440]) {
                    $validator->errors()->add('validation_viewports', 'Validation viewports must be exactly [390, 768, 1440] in ascending order.');
                }
            },
        ];
    }
}
