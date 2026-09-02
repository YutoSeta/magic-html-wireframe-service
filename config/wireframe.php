<?php

return [
    'service_token' => env('MAGIC_HTML_SERVICE_TOKEN'),
    'layout_service_token' => env('WIREFRAME_LAYOUT_SERVICE_TOKEN'),
    'requests_per_minute' => (int) env('WIREFRAME_REQUESTS_PER_MINUTE', 30),
    'idempotency' => [
        'store' => env('WIREFRAME_IDEMPOTENCY_STORE'),
        'lock_seconds' => (int) env('WIREFRAME_IDEMPOTENCY_LOCK_SECONDS', 10),
        'processing_ttl_seconds' => (int) env('WIREFRAME_IDEMPOTENCY_PROCESSING_TTL_SECONDS', 3600),
        'v2_response_ttl_seconds' => (int) env('WIREFRAME_IDEMPOTENCY_V2_RESPONSE_TTL_SECONDS', 86400),
    ],
    'jobs' => [
        'ttl_seconds' => (int) env('WIREFRAME_JOB_TTL_SECONDS', 86400),
        'section_start_batch' => (int) env('WIREFRAME_SECTION_START_BATCH', 6),
    ],
    'layout' => [
        'container_max_width_px' => (int) env('WIREFRAME_LAYOUT_CONTAINER_MAX_WIDTH_PX', 1120),
        'content_max_width_px' => (int) env('WIREFRAME_LAYOUT_CONTENT_MAX_WIDTH_PX', 760),
        'minimum_action_height_px' => (int) env('WIREFRAME_LAYOUT_MINIMUM_ACTION_HEIGHT_PX', 44),
        'validation_viewport_heights_px' => [
            390 => (int) env('WIREFRAME_LAYOUT_COMPACT_VIEWPORT_HEIGHT_PX', 844),
            768 => (int) env('WIREFRAME_LAYOUT_MEDIUM_VIEWPORT_HEIGHT_PX', 1024),
            1440 => (int) env('WIREFRAME_LAYOUT_WIDE_VIEWPORT_HEIGHT_PX', 900),
        ],
        'responsive' => [
            'compact_max_px' => (float) env('WIREFRAME_LAYOUT_COMPACT_MAX_PX', 719.98),
            'medium_min_px' => (int) env('WIREFRAME_LAYOUT_MEDIUM_MIN_PX', 720),
            'wide_min_px' => (int) env('WIREFRAME_LAYOUT_WIDE_MIN_PX', 1024),
        ],
    ],
    'layout_snapshots' => [
        'disk' => env('WIREFRAME_LAYOUT_SNAPSHOT_DISK', 'local'),
        'prefix' => env('WIREFRAME_LAYOUT_SNAPSHOT_PREFIX', 'layout-snapshots'),
        'ttl_seconds' => (int) env('WIREFRAME_LAYOUT_SNAPSHOT_TTL_SECONDS', 604800),
        'lock_seconds' => (int) env('WIREFRAME_LAYOUT_SNAPSHOT_LOCK_SECONDS', 15),
        'lock_wait_seconds' => (int) env('WIREFRAME_LAYOUT_SNAPSHOT_LOCK_WAIT_SECONDS', 5),
    ],
];
