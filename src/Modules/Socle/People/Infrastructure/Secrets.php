<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Core\Config\Config;

/** Clé secrète du module : configuration `people.secret_key`, à défaut variable d'environnement APP_KEY (jamais dans le dépôt). */
final class Secrets
{
    public static function key(Config $config): string
    {
        $key = (string) $config->get('people.secret_key', '');

        return $key !== '' ? $key : (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? '');
    }
}
