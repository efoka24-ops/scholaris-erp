<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Infrastructure;

use Scholaris\Core\Config\Config;

/** Emplacements lus par les sondes de santé (relatifs à la racine du projet, sauf chemin absolu). */
final class HealthPaths
{
    public function __construct(
        public readonly string $scheduleLast,
        public readonly string $scheduleLog,
        public readonly string $appLog,
        public readonly string $uploads,
        public readonly string $backups,
    ) {
    }

    public static function fromConfig(Config $config, string $root): self
    {
        $root = (string) $config->get('platform.health_root', $root);
        $resolve = static fn (string $path): string => preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) === 1 ? $path : rtrim($root, '/\\') . '/' . $path;

        return new self(
            $resolve('storage/schedule.last'),
            $resolve('storage/logs/schedule.log'),
            $resolve((string) $config->get('app.log_path', 'storage/logs/app.log')),
            $resolve((string) $config->get('organisation.storage_path', '') !== '' ? (string) $config->get('organisation.storage_path') : 'storage/uploads'),
            $resolve((string) $config->get('backup.path', 'storage/backups')),
        );
    }
}
