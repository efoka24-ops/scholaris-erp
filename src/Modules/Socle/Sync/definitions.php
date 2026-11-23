<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;
use function DI\get;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Sync\Application\HandlerRegistry;
use Scholaris\Modules\Socle\Sync\Console\ProcessBatchesCommand;
use Scholaris\Modules\Socle\Sync\Infrastructure\ContainerHandlerRegistry;
use Scholaris\Modules\Socle\Sync\Infrastructure\DeviceKeyCipher;

/**
 * Liaisons du module Sync (PP6). Réglage facultatif : `sync.secret_key` (à défaut, variable d'environnement APP_KEY) pour
 * chiffrer les clés d'appareil au repos ; sans clé, l'inscription d'un appareil échoue. Point d'extension UNIQUE : les
 * gestionnaires d'éléments sont déclarés sous `sync.handlers.<kind>` par les applications (voir Contracts\BatchItemHandler).
 */
return [
    HandlerRegistry::class => get(ContainerHandlerRegistry::class),
    ContainerHandlerRegistry::class => autowire(),
    DeviceKeyCipher::class => factory(static function (Config $config): DeviceKeyCipher {
        $key = (string) $config->get('sync.secret_key', '');
        if ($key === '') {
            $key = (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? '');
        }

        return new DeviceKeyCipher($key);
    }),

    // Cron : artisan sync:process (chaque minute).
    'console.command.' . ProcessBatchesCommand::NAME => autowire(ProcessBatchesCommand::class),
];
