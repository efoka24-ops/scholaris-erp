<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Reporting\Http\DashboardController;

/**
 * Routes du service S11 Tableaux de bord. GET /me/todo reste déclaré par Identity ; Reporting fournit l'agrégation
 * (TodoAggregator, liée au contrat Identity\TodoProvider dans definitions.php).
 */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/dashboards/{kind}', [DashboardController::class, 'show'], ['tenant' => false]);

    (require __DIR__ . '/routes_schedules.php')($routes);
};
