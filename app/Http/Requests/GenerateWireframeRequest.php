<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

final class GenerateWireframeRequest extends ContractRequest
{
    /** @return array<string,array<mixed>|string> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'site_ast' => ['required', 'array'],
            'brief' => ['required', 'array:organization,goals,audience,tone,requirements,materials'],
            'brief.organization' => ['required', 'string', 'min:1', 'max:1000'],
            'brief.goals' => ['required', 'string', 'min:1', 'max:4000'],
            'brief.audience' => ['required', 'string', 'min:1', 'max:4000'],
            'brief.tone' => ['required', 'string', 'min:1', 'max:2000'],
            'brief.requirements' => ['sometimes', 'string', 'max:8000'],
            'brief.materials' => ['sometimes', 'array', 'max:30'],
            'brief.materials.*' => ['string', 'max:8000'],
            'locale' => ['sometimes', 'string', 'min:2', 'max:20'],
        ];
    }

    /** @return array<int,callable(Validator):void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $document = json_decode($this->getContent());
                if (! is_object($document?->site_ast ?? null)) {
                    $validator->errors()->add('site_ast', 'The Site AST must be a JSON object.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['locale' => $this->input('locale', 'ja')]);
    }
}
