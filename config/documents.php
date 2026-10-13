<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

return [
    // Rendu PDF : « dompdf » (réel, hors ligne), « simulated » (tests) ; vide = dompdf en production, simulé ailleurs.
    'pdf_renderer' => (string) $env('PDF_RENDERER', ''),
    // Code QR de vérification : « library » (réel, sans GD), « simulated » ; vide = même règle que le rendu PDF.
    'qr_generator' => (string) $env('DOCUMENTS_QR', ''),
    // Dossier d'écriture de Dompdf (cache de polices), relatif à la racine du projet.
    'pdf_cache_path' => (string) $env('DOCUMENTS_PDF_CACHE', 'storage/cache/dompdf'),
    'pdf_paper' => 'A4',
    // File de génération en lot : taille d'une tranche, tentatives, délai avant reprise d'une tâche abandonnée (secondes).
    'queue' => [
        'chunk' => 50,
        'max_attempts' => 3,
        'stale_after' => 600,
    ],
];
