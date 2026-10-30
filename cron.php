<?php

declare(strict_types=1);

/**
 * Point d'entrée du cron de l'hébergement, qui n'accepte que `php -f <script>` et au plus une exécution toutes les
 * 15 minutes : une seule tâche, toutes les 15 minutes,
 *   php -f /home/<compte>/<application>/cron.php
 * équivaut à `artisan schedule:run` (voir Scholaris\Core\Console\Schedule pour la liste des tâches et leurs horaires).
 */

use Scholaris\Core\Console\Application;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/vendor/autoload.php';

exit((new Application(__DIR__))->run(['artisan', 'schedule:run']));
