<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Problem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<int,callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $topLevel = array_values(array_filter(
                array_keys($this->rules()),
                fn (string $key): bool => ! str_contains($key, '.'),
            ));
            foreach (array_diff(array_keys($this->all()), $topLevel) as $field) {
                $validator->errors()->add((string) $field, 'This field is not part of the selected contract.');
            }
        }];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(Problem::response(
            $this,
            422,
            'validation_failed',
            'The request does not satisfy the selected contract.',
            $validator->errors()->toArray(),
        ));
    }
}
