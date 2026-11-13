<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Console;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Audit\Application\BackupService;

/**
 * Commandes de sauvegarde (T143) :
 *   backup:run [--keep=N] [--path=dossier]   sauvegarde chiffrée de la base, puis rétention
 *   backup:restore <fichier> [--force]       restaure dans une base déjà migrée
 *   backup:keygen                            affiche une nouvelle clé (à conserver HORS du serveur)
 * La clé vient de BACKUP_KEY (config `backup.key`), jamais d'un argument de ligne de commande.
 */
final class BackupCommands
{
    public function __construct(private readonly BackupService $service, private readonly Config $config, private readonly string $basePath)
    {
    }

    /** @param list<string> $args */
    public function runBackup(array $args): int
    {
        $keep = (int) $this->config->get('backup.keep', 14);
        $directory = $this->directory((string) $this->config->get('backup.path', 'storage/backups'));
        foreach ($args as $arg) {
            if (preg_match('/^--keep=(\d{1,3})$/', $arg, $m) === 1) {
                $keep = (int) $m[1];
            } elseif (str_starts_with($arg, '--path=')) {
                $directory = $this->directory(substr($arg, 7));
            } else {
                fwrite(STDERR, "Usage : artisan backup:run [--keep=N] [--path=dossier]\n");

                return 2;
            }
        }
        $key = BackupService::parseKey((string) $this->config->get('backup.key', ''));
        $result = $this->service->backup($directory . '/' . $this->service->fileName(), $key);
        $deleted = $this->service->prune($directory, max(1, $keep));

        echo sprintf("Sauvegarde écrite : %s (%d octets, %d table(s), %d ligne(s)%s).\n", $result['path'], $result['bytes'], count($result['tables']), $result['rows'], $result['compressed'] ? ', compressée' : '');
        echo $deleted === [] ? "Rétention : rien à supprimer (conservées : {$keep}).\n" : 'Rétention : ' . count($deleted) . " ancienne(s) sauvegarde(s) supprimée(s) (conservées : {$keep}).\n";
        echo "Rappel : copiez ce fichier HORS du serveur ; sans la clé BACKUP_KEY il est illisible.\n";

        return 0;
    }

    /** @param list<string> $args */
    public function restore(array $args): int
    {
        $force = in_array('--force', $args, true);
        $files = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));
        if (count($files) !== 1 || count($args) - count($files) > ($force ? 1 : 0)) {
            fwrite(STDERR, "Usage : artisan backup:restore <fichier.sbk> [--force]\n");

            return 2;
        }
        $key = BackupService::parseKey((string) $this->config->get('backup.key', ''));
        $result = $this->service->restore($files[0], $key, $force);
        echo sprintf("Restauration terminée : %d table(s), %d ligne(s).\n", count($result['tables']), $result['rows']);

        return 0;
    }

    public function keygen(): int
    {
        echo "Nouvelle clé de sauvegarde (à placer dans BACKUP_KEY du .env ET à conserver dans un coffre HORS du serveur) :\n";
        echo BackupService::generateKey() . "\n";
        echo "Perdre cette clé rend toutes les sauvegardes illisibles ; la changer n'invalide pas les anciennes (gardez les anciennes clés).\n";

        return 0;
    }

    private function directory(string $path): string
    {
        $absolute = preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) === 1 ? $path : $this->basePath . '/' . $path;

        return rtrim(str_replace('\\', '/', $absolute), '/');
    }
}
