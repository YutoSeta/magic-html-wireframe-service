<?php

namespace App\Services\Contracts;

interface ReportsWireframeTelemetry
{
    /**
     * @return array{
     *     provider:string,
     *     model:string|null,
     *     response_id:string|null,
     *     input_tokens:int|null,
     *     cached_input_tokens:int|null,
     *     output_tokens:int|null,
     *     reasoning_tokens:int|null,
     *     estimated_cost:float|null,
     *     provider_request_count:int,
     *     semantic_attempt_count:int,
     *     retry_count:int,
     *     provider_duration_ms:int,
     *     rate_card:array{version:string,effective_at:string,source:string,currency:string,model:string}|null
     * }|null
     */
    public function telemetry(): ?array;
}
