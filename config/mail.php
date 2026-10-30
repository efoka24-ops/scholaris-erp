<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

return [
    // « log » (défaut) : simulateur sans envoi. « smtp » n'est effectif que si MAIL_HOST est renseigné.
    'mailer' => strtolower((string) $env('MAIL_MAILER', 'log')),
    'host' => (string) $env('MAIL_HOST', ''),
    'port' => (int) $env('MAIL_PORT', 587),
    // tls = STARTTLS (port 587), ssl = TLS implicite (port 465), none = clair (boucle locale uniquement avec authentification).
    'encryption' => (string) $env('MAIL_ENCRYPTION', 'tls'),
    'username' => (string) $env('MAIL_USERNAME', ''),
    // Mot de passe : uniquement dans le .env local ou du serveur, jamais dans le dépôt.
    'password' => (string) $env('MAIL_PASSWORD', ''),
    'from_address' => (string) $env('MAIL_FROM_ADDRESS', ''),
    'from_name' => (string) $env('MAIL_FROM_NAME', 'SCHOLARIS'),
    'timeout_seconds' => 15,
];
