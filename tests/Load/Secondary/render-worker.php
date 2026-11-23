<?php

declare(strict_types=1);

/**
 * Processus de rendu PDF des bulletins pour la mesure parallèle de NF-S2 (C-117) : rend en profil léger (LightPdfProfile, comme
 * GET /documents/{id}/preview?profile=light) les contenus HTML listés, après un rendu de chauffe (cache des polices de Dompdf).
 *
 *   php render-worker.php <liste.txt> <dossier-cache>     liste : un chemin de fichier HTML par ligne
 *
 * Affiche en JSON : {"documents": n, "seconds": durée des rendus mesurés, "bytes": taille totale}.
 */

use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Domain\LightPdfProfile;
use Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$files = array_values(array_filter(array_map('trim', (array) file((string) ($argv[1] ?? ''))), static fn (string $f): bool => $f !== ''));
$renderer = new DompdfRenderer((string) ($argv[2] ?? sys_get_temp_dir() . '/dompdf-worker'), 'A4');
$light = static fn (string $path): string => LightPdfProfile::apply(DocumentService::withPrintStyle((string) file_get_contents($path), LightPdfProfile::STYLE));
if ($files !== []) {
    $renderer->render($light($files[0]));
}
$bytes = 0;
$t0 = microtime(true);
foreach ($files as $file) {
    $bytes += strlen($renderer->render($light($file)));
}
echo json_encode(['documents' => count($files), 'seconds' => round(microtime(true) - $t0, 3), 'bytes' => $bytes]), "\n";
