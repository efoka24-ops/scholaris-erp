<?php

declare(strict_types=1);

use Scholaris\Core\Http\Controller\HealthController;
use Scholaris\Core\Http\RouteRegistry;

/**
 * Routes du cœur. Les modules déclarent les leurs dans Modules/<Groupe>/<Module>/routes.php,
 * sous la forme d'une fonction recevant le RouteRegistry. Le préfixe /api/v1 est ajouté par le registre.
 */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/health', [HealthController::class, 'show'], ['public' => true]);
};
