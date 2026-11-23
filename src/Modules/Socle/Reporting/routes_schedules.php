<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Reporting\Http\ScheduleController;

/** Routes des rapports planifiés et des statistiques réglementaires (T134), chargées par routes.php. Établissement actif exigé. */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/report-schedules', [ScheduleController::class, 'index']);
    $routes->add('POST', '/report-schedules', [ScheduleController::class, 'store']);
    $routes->add('GET', '/report-schedules/{id}', [ScheduleController::class, 'show']);
    $routes->add('PATCH', '/report-schedules/{id}', [ScheduleController::class, 'update']);
    $routes->add('DELETE', '/report-schedules/{id}', [ScheduleController::class, 'destroy']);
    $routes->add('POST', '/report-schedules/{id}/run', [ScheduleController::class, 'run']);
    $routes->add('GET', '/report-schedules/{id}/runs', [ScheduleController::class, 'runs']);
    $routes->add('GET', '/regulatory-statistics', [ScheduleController::class, 'regulatory']);
};
