<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

final class PatchLayoutSnapshotRequest extends ContractRequest
{
    /** @var list<string> */
    private const OPERATIONS = [
        'set_cluster_wrap', 'set_flow', 'set_columns', 'set_column_ratio', 'set_gap',
        'set_section_spacing', 'set_alignment', 'set_image_aspect', 'set_object_fit',
        'set_container_max_width', 'set_content_max_width', 'set_minimum_action_height',
    ];

    /** @var list<string> */
    private const PROBLEMS = [
        'unintended_wrap', 'horizontal_overflow', 'overlap', 'clipped_content',
        'zero_dimension', 'container_too_wide', 'container_too_narrow',
        'content_too_wide', 'content_too_narrow', 'spacing_too_tight',
        'spacing_too_loose', 'misalignment', 'weak_hierarchy', 'column_imbalance',
        'incorrect_reflow', 'incorrect_mobile_order', 'image_aspect_mismatch',
        'object_fit_mismatch', 'form_control_too_small', 'action_too_small',
    ];

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'page_key' => ['required', 'regex:/\A[a-z0-9][a-z0-9-]{0,99}\z/D'],
            'patch_plan' => ['required', 'array:contract_version,stage,base_ast_digest,issues'],
            'patch_plan.contract_version' => ['required', 'in:1.0'],
            'patch_plan.stage' => ['required', 'in:layout'],
            'patch_plan.base_ast_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'patch_plan.issues' => ['required', 'array', 'min:1', 'max:20'],
            'patch_plan.issues.*' => ['required', 'array:target_id,viewport,problem,operation'],
            'patch_plan.issues.*.target_id' => ['required', 'regex:/\A(?:\$constraints|[a-z][a-z0-9._-]{0,199})\z/D'],
            'patch_plan.issues.*.viewport' => ['required', 'array:min_width,max_width', 'min:1'],
            'patch_plan.issues.*.viewport.min_width' => ['sometimes', 'integer', 'between:320,7680'],
            'patch_plan.issues.*.viewport.max_width' => ['sometimes', 'integer', 'between:320,7680'],
            'patch_plan.issues.*.problem' => ['required', 'in:'.implode(',', self::PROBLEMS)],
            'patch_plan.issues.*.operation' => ['required', 'array:type,value'],
            'patch_plan.issues.*.operation.type' => ['required', 'in:'.implode(',', self::OPERATIONS)],
            'patch_plan.issues.*.operation.value' => ['required'],
        ];
    }

    /** @return array<int,callable(Validator):void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $document = json_decode($this->getContent());
                $plan = $document?->patch_plan ?? null;
                if (! is_object($plan) || ! is_array($plan->issues ?? null)) {
                    $validator->errors()->add('patch_plan', 'The patch plan must be a JSON object containing an issues list.');

                    return;
                }

                foreach ($plan->issues as $index => $issue) {
                    if (! is_object($issue)
                        || ! is_object($issue->viewport ?? null)
                        || ! is_object($issue->operation ?? null)) {
                        $validator->errors()->add("patch_plan.issues.{$index}", 'Each patch issue, viewport, and operation must be a JSON object.');

                        continue;
                    }
                    $minimum = $issue->viewport->min_width ?? null;
                    $maximum = $issue->viewport->max_width ?? null;
                    if (is_int($minimum) && is_int($maximum) && $minimum > $maximum) {
                        $validator->errors()->add("patch_plan.issues.{$index}.viewport", 'The viewport minimum cannot exceed its maximum.');
                    }
                    if (! $this->operationValueIsValid(
                        $issue->operation->type ?? null,
                        $issue->operation->value ?? null,
                    )) {
                        $validator->errors()->add("patch_plan.issues.{$index}.operation.value", 'The operation value is invalid for its finite Layout operation.');
                    }
                    $operationType = $issue->operation->type ?? null;
                    $targetsSiteConstraints = ($issue->target_id ?? null) === '$constraints';
                    if (in_array($operationType, ['set_container_max_width', 'set_content_max_width', 'set_minimum_action_height'], true)) {
                        if (! $targetsSiteConstraints) {
                            $validator->errors()->add("patch_plan.issues.{$index}.target_id", 'Layout constraint operations must target the site constraints.');
                        }
                        if ($minimum !== 320 || property_exists($issue->viewport, 'max_width')) {
                            $validator->errors()->add("patch_plan.issues.{$index}.viewport", 'A site constraint patch must use the canonical all-device viewport with min_width 320 only.');
                        }
                    } elseif ($targetsSiteConstraints) {
                        $validator->errors()->add("patch_plan.issues.{$index}.target_id", 'Only a Layout constraint operation may target the site constraints.');
                    }
                }
            },
        ];
    }

    private function operationValueIsValid(mixed $type, mixed $value): bool
    {
        if (! is_string($type)) {
            return false;
        }

        return match ($type) {
            'set_cluster_wrap' => in_array($value, ['wrap', 'nowrap'], true),
            'set_flow' => in_array($value, ['stack', 'cluster', 'row', 'grid', 'split', 'overlay'], true),
            'set_columns' => in_array($value, ['one', 'two', 'three', 'auto-fit'], true),
            'set_column_ratio' => is_string($value)
                && preg_match('/\A(?:[0-9]*\.?[0-9]+fr)(?:\s+[0-9]*\.?[0-9]+fr){1,5}\z/D', $value) === 1,
            'set_gap', 'set_section_spacing', 'set_image_aspect' => is_string($value)
                && preg_match('/\A(?:[a-z]|[0-9]+[a-z])[a-z0-9-]{0,99}\z/D', $value) === 1,
            'set_alignment' => in_array($value, ['start', 'center', 'end', 'stretch', 'baseline'], true),
            'set_object_fit' => in_array($value, ['cover', 'contain', 'fill', 'none', 'scale-down'], true),
            'set_container_max_width' => is_int($value) && $value >= 320 && $value <= 3840,
            'set_content_max_width' => is_int($value) && $value >= 240 && $value <= 3840,
            'set_minimum_action_height' => is_int($value) && $value >= 44 && $value <= 256,
            default => false,
        };
    }
}
