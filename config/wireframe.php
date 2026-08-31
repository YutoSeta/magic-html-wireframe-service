<?php

return [
    'service_token' => env('MAGIC_HTML_SERVICE_TOKEN'),
    'requests_per_minute' => (int) env('WIREFRAME_REQUESTS_PER_MINUTE', 30),
    'idempotency' => [
        'store' => env('WIREFRAME_IDEMPOTENCY_STORE'),
        'lock_seconds' => (int) env('WIREFRAME_IDEMPOTENCY_LOCK_SECONDS', 10),
        'processing_ttl_seconds' => (int) env('WIREFRAME_IDEMPOTENCY_PROCESSING_TTL_SECONDS', 3600),
        'v2_response_ttl_seconds' => (int) env('WIREFRAME_IDEMPOTENCY_V2_RESPONSE_TTL_SECONDS', 86400),
    ],
];
