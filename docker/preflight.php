<?php

// Check environment without booting Laravel or contacting database services.
$fail = static function (string $name): never {
    fwrite(STDERR, "Configuração de produção inválida: {$name}. Consulte deploy/README.md.\n");
    exit(1);
};
$env = static fn (string $name): string => (string) (getenv($name) ?: '');
$boolean = static fn (string $name): ?bool => filter_var(getenv($name), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($env('APP_ENV') !== 'production') {
    $fail('APP_ENV');
}
if ($env('APP_DEBUG') === '' || $boolean('APP_DEBUG') !== false) {
    $fail('APP_DEBUG');
}
$key = $env('APP_KEY');
$decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
if (! is_string($decoded) || strlen($decoded) !== 32) {
    $fail('APP_KEY');
}
foreach (['APP_URL', 'FRONTEND_URL'] as $name) {
    $parts = parse_url($env($name));
    if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || ! in_array($parts['path'] ?? '', ['', '/'], true)
        || str_ends_with($parts['host'], '.invalid')) {
        $fail($name);
    }
}
foreach (['SESSION_SECURE_COOKIE', 'SESSION_HTTP_ONLY', 'SESSION_ENCRYPT'] as $name) {
    if ($boolean($name) !== true) {
        $fail($name);
    }
}
foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST', 'REDIS_PASSWORD', 'SESSION_DOMAIN', 'SANCTUM_STATEFUL_DOMAINS', 'TRUSTED_PROXIES'] as $name) {
    if (trim($env($name)) === '' || $env($name) === 'null') {
        $fail($name);
    }
}
$domain = ltrim($env('SESSION_DOMAIN'), '.');
foreach (['APP_URL', 'FRONTEND_URL'] as $name) {
    $host = parse_url($env($name), PHP_URL_HOST);
    if ($host !== $domain && ! str_ends_with($host, '.'.$domain)) {
        $fail('SESSION_DOMAIN');
    }
}
$frontend = parse_url($env('FRONTEND_URL'));
$frontendAuthority = $frontend['host'].(isset($frontend['port']) ? ':'.$frontend['port'] : '');
if (! in_array($frontendAuthority, array_map('trim', explode(',', $env('SANCTUM_STATEFUL_DOMAINS'))), true)) {
    $fail('SANCTUM_STATEFUL_DOMAINS');
}
if (str_contains($env('TRUSTED_PROXIES'), '*')) {
    $fail('TRUSTED_PROXIES');
}
foreach (['DB_CONNECTION' => 'mysql', 'QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'database', 'SESSION_SAME_SITE' => 'lax'] as $name => $value) {
    if ($env($name) !== $value) {
        $fail($name);
    }
}
$retryAfter = $env('REDIS_QUEUE_RETRY_AFTER') ?: '90';
if (! ctype_digit($retryAfter) || (int) $retryAfter <= 60) {
    $fail('REDIS_QUEUE_RETRY_AFTER');
}
if ($env('DEV_USER_PASSWORD') !== '' || $boolean('INTERNAL_KANBAN_ENABLED') !== false) {
    $fail('DEV_USER_PASSWORD / INTERNAL_KANBAN_ENABLED');
}
