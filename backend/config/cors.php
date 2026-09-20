<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'Accept', 'Origin'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
