<?php

declare(strict_types=1);

use Scholaris\Core\Http\ResponseEmitter;
use Scholaris\Core\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = Kernel::boot(dirname(__DIR__));
$response = $kernel->handleGlobals();

(new ResponseEmitter())->emit($response);
