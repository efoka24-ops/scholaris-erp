<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

/**
 * Sauvegardes chiffrées (backup:run / backup:restore). La clé n'est JAMAIS dans le dépôt : variable BACKUP_KEY du .env du
 * serveur, générée par « php artisan backup:keygen » et conservée aussi dans un coffre hors serveur.
 */
return [
    'key' => (string) $env('BACKUP_KEY', ''),
    // Dossier des sauvegardes, relatif à la racine du projet (hors racine web : ne jamais le placer sous public/).
    'path' => (string) $env('BACKUP_PATH', 'storage/backups'),
    // Nombre de sauvegardes conservées (la plus ancienne est supprimée au-delà).
    'keep' => (int) $env('BACKUP_KEEP', 14),
];
