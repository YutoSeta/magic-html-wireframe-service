<?php

return [
    'service_token' => env('MAGIC_HTML_SERVICE_TOKEN'),
    'requests_per_minute' => (int) env('WIREFRAME_REQUESTS_PER_MINUTE', 30),
];
