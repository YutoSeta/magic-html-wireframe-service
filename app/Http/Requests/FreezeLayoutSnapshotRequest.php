<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

final class FreezeLayoutSnapshotRequest extends ContractRequest
{
    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'in:1.0'],
            'candidate_snapshot_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'validation' => ['required', 'array:status,validator,validator_version,subject,pages'],
            'validation.status' => ['required', 'in:passed'],
            'validation.validator' => ['required', 'in:magic-html-preview-service'],
            'validation.validator_version' => ['required', 'string', 'regex:/\A[1-9][0-9]*\.[0-9]+\z/D'],
            'validation.subject' => ['required', 'array:candidate_snapshot_id,candidate_snapshot_digest'],
            'validation.subject.candidate_snapshot_id' => ['required', 'regex:/\Als_[a-f0-9]{64}\z/D'],
            'validation.subject.candidate_snapshot_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'validation.pages' => ['required', 'array', 'min:1', 'max:8'],
            'validation.pages.*' => ['required', 'array:page_key,route,path,viewports'],
            'validation.pages.*.page_key' => ['required', 'regex:/\A[a-z0-9][a-z0-9-]{0,99}\z/D', 'distinct:strict'],
            'validation.pages.*.route' => ['required', 'string', 'regex:#\A/(?:[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)*)?\z#D', 'distinct:strict'],
            'validation.pages.*.path' => ['required', 'string', 'regex:#\A(?:[a-z0-9][a-z0-9-]*/)*[a-z0-9][a-z0-9-]*\.html\z#D', 'distinct:strict'],
            'validation.pages.*.viewports' => ['required', 'array', 'size:3'],
            'validation.pages.*.viewports.*' => ['required', 'array:width_px,height_px,breakpoint,breakpoint_outcome,geometry_outcome,measurements,issues'],
            'validation.pages.*.viewports.*.width_px' => ['required', 'integer'],
            'validation.pages.*.viewports.*.height_px' => ['required', 'integer', 'between:320,4320'],
            'validation.pages.*.viewports.*.breakpoint' => ['required', 'in:compact,medium,wide'],
            'validation.pages.*.viewports.*.breakpoint_outcome' => ['required', 'in:passed'],
            'validation.pages.*.viewports.*.geometry_outcome' => ['required', 'in:passed'],
            'validation.pages.*.viewports.*.measurements' => ['required', 'array:container_width_px,content_width_px,horizontal_overflow_px,minimum_action_height_px,overlap_count,clipped_content_count,zero_dimension_count,responsive_violation_count,image_aspect_violation_count,checks'],
            'validation.pages.*.viewports.*.measurements.container_width_px' => ['required', 'numeric', 'min:0'],
            'validation.pages.*.viewports.*.measurements.content_width_px' => ['required', 'numeric', 'min:0'],
            'validation.pages.*.viewports.*.measurements.horizontal_overflow_px' => ['required', 'numeric', 'between:-0.0001,0.0001'],
            'validation.pages.*.viewports.*.measurements.minimum_action_height_px' => ['required', 'numeric', 'min:44'],
            'validation.pages.*.viewports.*.measurements.overlap_count' => ['required', 'integer', 'in:0'],
            'validation.pages.*.viewports.*.measurements.clipped_content_count' => ['required', 'integer', 'in:0'],
            'validation.pages.*.viewports.*.measurements.zero_dimension_count' => ['required', 'integer', 'in:0'],
            'validation.pages.*.viewports.*.measurements.responsive_violation_count' => ['required', 'integer', 'in:0'],
            'validation.pages.*.viewports.*.measurements.image_aspect_violation_count' => ['required', 'integer', 'in:0'],
            'validation.pages.*.viewports.*.measurements.checks' => ['required', 'array:container_width_within_limit,content_width_within_limit,no_horizontal_overflow,minimum_action_height_met,source_order_preserved,no_overlap,no_clipped_content,no_zero_dimensions,responsive_behavior_matches,image_aspect_preserved'],
            'validation.pages.*.viewports.*.measurements.checks.container_width_within_limit' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.content_width_within_limit' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.no_horizontal_overflow' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.minimum_action_height_met' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.source_order_preserved' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.no_overlap' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.no_clipped_content' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.no_zero_dimensions' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.responsive_behavior_matches' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.measurements.checks.image_aspect_preserved' => ['required', 'accepted'],
            'validation.pages.*.viewports.*.issues' => ['present', 'array', 'max:50'],
            'validation.pages.*.viewports.*.issues.*' => ['required', 'array:code,message'],
            'validation.pages.*.viewports.*.issues.*.code' => ['required', 'in:breakpoint_mismatch,container_overflow,content_overflow,horizontal_overflow,action_height,source_order,overlap,clipped_content,zero_or_extreme_dimension,section_spacing,line_length,grid_columns,column_ratio,image_aspect,form_dimensions,mobile_order,breakpoint_discontinuity,anchor_collision'],
            'validation.pages.*.viewports.*.issues.*.message' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<int,callable(Validator):void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $pages = $this->input('validation.pages');
                if (! is_array($pages) || ! array_is_list($pages)) {
                    return;
                }
                foreach ($pages as $index => $page) {
                    $viewports = is_array($page) ? ($page['viewports'] ?? null) : null;
                    if (! is_array($viewports) || ! array_is_list($viewports)) {
                        continue;
                    }
                    $actual = array_map(static fn (mixed $viewport): array => is_array($viewport)
                        ? [$viewport['width_px'] ?? null, $viewport['breakpoint'] ?? null]
                        : [null, null], $viewports);
                    if ($actual !== [[390, 'compact'], [768, 'medium'], [1440, 'wide']]) {
                        $validator->errors()->add(
                            "validation.pages.{$index}.viewports",
                            'Browser validation must contain 390px, 768px, and 1440px results in order.',
                        );
                    }
                }
            },
        ];
    }
}
