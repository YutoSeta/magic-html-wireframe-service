<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

final class MaterializeWireframeRequest extends ContractRequest
{
    /** @return array<string,array<mixed>|string> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'wireframe_ast' => ['required', 'array:version,pages'],
            'wireframe_ast.version' => ['required', 'integer', 'in:1'],
            'wireframe_ast.pages' => ['required', 'array', 'min:1', 'max:20'],
            'wireframe_ast.pages.*' => ['required', 'array:key,sections'],
            'wireframe_ast.pages.*.key' => ['required', 'string', 'min:1', 'max:100'],
            'wireframe_ast.pages.*.sections' => ['required', 'array', 'min:2', 'max:8'],
            'wireframe_ast.pages.*.sections.*' => ['required', 'array:key,composition,roles'],
            'wireframe_ast.pages.*.sections.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'wireframe_ast.pages.*.sections.*.composition' => ['required', 'in:hero,feature-grid,content,steps,testimonials,faq,cta,contact'],
            'wireframe_ast.pages.*.sections.*.roles' => ['required', 'array', 'min:1', 'max:6'],
            'wireframe_ast.pages.*.sections.*.roles.*' => ['required', 'in:Eyebrow,Title,Text,Items,Actions,Image'],
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
                }
            },
        ];
    }
}
