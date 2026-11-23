<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

return [
    'name' => $env('APP_NAME', 'SCHOLARIS'),
    'env' => $env('APP_ENV', 'production'),
    'debug' => filter_var($env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
    'url' => $env('APP_URL', 'http://localhost:8000'),
    'timezone' => $env('APP_TIMEZONE', 'Africa/Douala'),
    'locale' => $env('APP_LOCALE', 'fr'),
    'two_factor_required' => filter_var($env('AUTH_2FA_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),
    'api_prefix' => '/api/v1',
    'log_path' => $env('LOG_PATH', 'storage/logs/app.log'),
];
