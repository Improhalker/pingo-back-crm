<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => explode(',', env('FRONTEND_URL', 'http://localhost:3000')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN', 'X-Organization-Id', 'X-Requested-With', 'Idempotency-Key'],
    'exposed_headers' => ['X-Request-Id', 'Idempotent-Replayed'],
    'max_age' => 600,
    'supports_credentials' => true,
];
