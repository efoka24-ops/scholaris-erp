<?php

declare(strict_types=1);

/**
 * Routeur du serveur PHP intégré pour le banc de charge (équivalent de public/index.php, mais avec la configuration explicite
 * de LoadEnvironment au lieu du fichier .env). Une requête = un démarrage du noyau, comme sous PHP-FPM.
 *
 *   LOADTEST_DB=<fichier.sqlite> php -S 127.0.0.1:8101 tests/Load/server.php
 */

use Scholaris\Core\Http\ResponseEmitter;
use Tests\Load\Support\LoadEnvironment;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$database = (string) getenv('LOADTEST_DB');
if ($database === '' || !is_file($database)) {
    http_response_code(500);
    echo 'LOADTEST_DB absent';

    return true;
}

$kernel = LoadEnvironment::kernel($database);
(new ResponseEmitter())->emit($kernel->handleGlobals());

return true;
