<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;

final class GenerateWireframeRequest extends ContractRequest
{
    /** @return array<string,array<mixed>|string> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'wireframe_ast_version' => ['sometimes', 'integer', 'in:1,2'],
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
            function (Validator $validator): void {
                $idempotencyKey = $this->header('Idempotency-Key');
                if (! is_string($idempotencyKey)
                    || Str::length($idempotencyKey) < 8
                    || Str::length($idempotencyKey) > 200) {
                    $validator->errors()->add('Idempotency-Key', 'The Idempotency-Key header must be between 8 and 200 characters.');
                }
            },
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'locale' => $this->input('locale', 'ja'),
            'wireframe_ast_version' => $this->input('wireframe_ast_version', 1),
        ]);
    }
}
