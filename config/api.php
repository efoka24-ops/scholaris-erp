<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

return [
    // Origines autorisées pour CORS (aucune par défaut), séparées par des virgules dans CORS_ORIGINS.
    'cors_origins' => array_values(array_filter(array_map('trim', explode(',', (string) $env('CORS_ORIGINS', ''))))),

    // POST soumis à l'en-tête Idempotency-Key (motifs regex sur le chemin).
    'idempotent_paths' => [
        '#^/api/v1/payments$#',
        '#^/api/v1/cash/receipts$#',
        '#^/api/v1/documents$#',
    ],

    // Limites de débit : [méthode, motif du chemin, nombre d'appels, fenêtre en secondes].
    'rate_limits' => [
        ['POST', '#^/api/v1/auth/login$#', 10, 60],
        ['POST', '#^/api/v1/auth/(activation|password|2fa)#', 20, 3600],
        // Mot de passe de la personne connectée : changement et confirmation (en plus du verrouillage à 5 échecs).
        ['POST', '#^/api/v1/auth/(change-password|reconfirm)$#', 10, 600],
        // Création de comptes à mot de passe provisoire et ré-émission (en plus de 5 ré-émissions par heure et par compte).
        ['POST', '#^/api/v1/users(/[^/]+/provisional-password)?$#', 120, 3600],
        ['POST', '#^/api/v1/(payments|cash/receipts)$#', 60, 60],
        ['*', '#^/api/v1/public/#', 30, 60],
        ['POST', '#^/api/v1/messages(/[^/]+/send)?$#', 30, 60],
        ['*', '#^/api/v1/#', 600, 60],
    ],

    // Taille maximale d'un corps JSON en octets (413 au-delà) ; les imports CSV et téléversements n'y sont pas soumis.
    'max_json_body_bytes' => (int) $env('API_MAX_JSON_BODY_BYTES', 2097152),

    'payment_webhook_secret' => $env('PAYMENT_WEBHOOK_SECRET', ''),
];
