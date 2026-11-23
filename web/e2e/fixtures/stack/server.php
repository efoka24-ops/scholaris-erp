<?php

declare(strict_types=1);

/** Routeur de `php -S` pour la pile réelle des tests Playwright : toute requête passe par le Kernel. */

use Scholaris\Core\Http\ResponseEmitter;

/** @var Scholaris\Core\Kernel $kernel */
$kernel = require __DIR__ . '/boot.php';
(new ResponseEmitter())->emit($kernel->handleGlobals());
