<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

return [
    'driver' => $env('DB_CONNECTION', 'mysql'),
    'host' => $env('DB_HOST', '127.0.0.1'),
    'port' => (int) $env('DB_PORT', 3306),
    'database' => ($driver = $env('DB_CONNECTION', 'mysql')) === 'sqlite' && !preg_match('#^(:memory:|/|[A-Za-z]:[\/])#', (string) $env('DB_DATABASE', ':memory:'))
        ? dirname(__DIR__) . '/' . $env('DB_DATABASE')
        : $env('DB_DATABASE', 'scholaris'),
    'username' => $env('DB_USERNAME', ''),
    'password' => $env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
