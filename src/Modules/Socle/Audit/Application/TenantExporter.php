<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Contracts\ChunkedTenantDataSource;
use ZipArchive;

/**
 * Export complet d'un client : archive ZIP de fichiers JSON, un dossier par source enregistrée. Journalisé.
 * Les sources par lots (ChunkedTenantDataSource) sont écrites table par table sur disque : la mémoire ne dépend pas du volume.
 */
final class TenantExporter
{
    public function __construct(
        private readonly DataSources $sources,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly string $timezone = 'Africa/Douala',
        private readonly int $batchSize = 500,
    ) {
    }

    /**
     * Écrit l'archive dans $path et renvoie le manifeste (sources et nombre de lignes).
     *
     * @return array<string, mixed>
     */
    public function exportToFile(string $tenantId, string $actorId, string $path): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $tenantId) !== 1) {
            throw HttpException::validation(['tenant_id' => ['Identifiant de client invalide.']]);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer l\'archive : ' . $path);
        }

        $counts = [];
        $temporary = [];
        try {
            foreach ($this->sources->tenantSources() as $source) {
                $name = (string) preg_replace('/[^a-z0-9_-]+/i', '_', $source->name());
                if ($source instanceof ChunkedTenantDataSource) {
                    $counts[$name] = $this->addChunked($zip, $source, $tenantId, $name, $temporary);

                    continue;
                }
                foreach ($source->export($tenantId) as $table => $rows) {
                    $table = preg_replace('/[^a-z0-9_-]+/i', '_', (string) $table);
                    $zip->addFromString($name . '/' . $table . '.json', self::json($rows));
                    $counts[$name][$table] = count($rows);
                }
            }
            $manifest = [
                'tenant_id' => $tenantId,
                'generated_at' => (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone($this->timezone))->format(DATE_ATOM),
                'format' => 'scholaris-tenant-export/1',
                'sources' => $counts,
            ];
            $zip->addFromString('manifest.json', self::json($manifest));
            if (!$zip->close()) {
                throw new RuntimeException('Écriture de l\'archive impossible : ' . $path);
            }
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        $this->audit->append($tenantId, $actorId, 'privacy.tenant_export', 'tenant', $tenantId, null, ['sources' => $counts]);

        return $manifest;
    }

    /** Archive en mémoire (réponse HTTP). */
    public function exportToString(string $tenantId, string $actorId): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scx');
        if ($path === false) {
            throw new RuntimeException('Fichier temporaire indisponible.');
        }
        try {
            $this->exportToFile($tenantId, $actorId, $path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Écrit chaque table dans un fichier temporaire, lot après lot (tableau JSON), puis l'ajoute à l'archive une fois le
     * fichier complet (la taille est figée à l'ajout).
     *
     * @param list<string> $temporary fichiers à supprimer après la fermeture de l'archive
     * @return array<string, int> nombre de lignes par table
     */
    private function addChunked(ZipArchive $zip, ChunkedTenantDataSource $source, string $tenantId, string $name, array &$temporary): array
    {
        $counts = [];
        $handle = null;
        $file = '';
        $current = '';
        foreach ($source->exportChunks($tenantId, $this->batchSize) as [$table, $rows]) {
            $table = (string) preg_replace('/[^a-z0-9_-]+/i', '_', $table);
            if ($handle === null || $table !== $current) {
                if ($handle !== null) {
                    $this->seal($zip, $handle, $file, $name . '/' . $current . '.json', $counts[$current]);
                }
                $file = (string) tempnam(sys_get_temp_dir(), 'scx');
                $handle = $file === '' ? false : fopen($file, 'wb');
                if ($handle === false) {
                    throw new RuntimeException('Fichier temporaire indisponible.');
                }
                $temporary[] = $file;
                fwrite($handle, '[');
                $current = $table;
                $counts[$table] = 0;
            }
            foreach ($rows as $row) {
                fwrite($handle, ($counts[$table] === 0 ? "\n" : ",\n") . json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                $counts[$table]++;
            }
        }
        if ($handle !== null) {
            $this->seal($zip, $handle, $file, $name . '/' . $current . '.json', $counts[$current]);
        }

        return $counts;
    }

    /** @param resource $handle */
    private function seal(ZipArchive $zip, $handle, string $file, string $entry, int $count): void
    {
        fwrite($handle, $count === 0 ? ']' : "\n]");
        fclose($handle);
        $zip->addFile($file, $entry);
    }
    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
