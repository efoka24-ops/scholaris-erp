<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

return [
    // Désactivé par défaut : aucun appel réel tant que PAYMENT_GATEWAY n'est pas « sungku ».
    'enabled' => strtolower((string) $env('PAYMENT_GATEWAY', 'simulator')) === 'sungku',
    'base_url' => rtrim((string) $env('SUNGKU_BASE_URL', 'https://sungku.trugroup.cm'), '/'),
    'api_key' => (string) $env('SUNGKU_API_KEY', ''),
    'webhook_secret' => (string) $env('SUNGKU_WEBHOOK_SECRET', ''),
    'timeout_seconds' => 15,
    // Tolérance sur l'horodatage d'un webhook (secondes) : au-delà, la livraison est refusée (rejeu).
    'webhook_tolerance_seconds' => 600,
];
