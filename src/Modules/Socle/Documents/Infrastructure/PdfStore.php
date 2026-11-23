<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use RuntimeException;
use Scholaris\Core\Config\Config;

/**
 * Stockage des PDF pré-rendus (C-118) : `<documents.pdf_store_path>/<client>/<document>-<profil>.pdf`. C'est un cache : un
 * document émis est figé, son PDF se refait depuis le contenu enregistré si le fichier manque.
 * Écriture atomique (fichier temporaire puis renommage) : un lecteur ne voit jamais un PDF partiel.
 */
final class PdfStore
{
    private readonly string $root;

    public function __construct(Config $config)
    {
        $path = (string) $config->get('documents.pdf_store_path', 'storage/cache/documents-pdf');
        $this->root = preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) === 1 ? $path : dirname(__DIR__, 5) . '/' . $path;
    }

    public function get(string $tenantId, string $documentId, string $profile): ?string
    {
        $file = $this->path($tenantId, $documentId, $profile);
        if (!is_file($file)) {
            return null;
        }
        $bytes = file_get_contents($file);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    public function put(string $tenantId, string $documentId, string $profile, string $pdf): void
    {
        $file = $this->path($tenantId, $documentId, $profile);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new RuntimeException('Dossier des PDF impossible à créer.');
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $pdf) === false || !rename($tmp, $file)) {
            @unlink($tmp);

            throw new RuntimeException('Écriture du PDF impossible.');
        }
    }

    private function path(string $tenantId, string $documentId, string $profile): string
    {
        $safe = static fn (string $v): string => (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $v);

        return $this->root . '/' . $safe($tenantId) . '/' . $safe($documentId) . '-' . $safe($profile) . '.pdf';
    }
}
