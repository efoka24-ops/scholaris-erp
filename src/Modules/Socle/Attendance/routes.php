<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Attendance\Http\AssiduityController;
use Scholaris\Modules\Socle\Attendance\Http\DisciplineController;
use Scholaris\Modules\Socle\Attendance\Http\JustificationController;
use Scholaris\Modules\Socle\Attendance\Http\PickupController;
use Scholaris\Modules\Socle\Attendance\Http\RecordController;
use Scholaris\Modules\Socle\Attendance\Http\SessionController;

/** Routes du module Attendance (S08). Le préfixe /api/v1 est ajouté par le registre ; toutes exigent X-Establishment-Id. */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/attendance-sessions', [SessionController::class, 'index']);
    $routes->add('POST', '/attendance-sessions', [SessionController::class, 'store']);
    $routes->add('POST', '/attendance-sessions/sync', [SessionController::class, 'sync']);
    $routes->add('GET', '/attendance-sessions/export', [SessionController::class, 'export']);
    $routes->add('GET', '/attendance-sessions/{id}', [SessionController::class, 'show']);
    $routes->add('PUT', '/attendance-sessions/{id}/records', [SessionController::class, 'saveRecords']);
    $routes->add('POST', '/attendance-sessions/{id}/validate', [SessionController::class, 'validateCall']);
    $routes->add('POST', '/attendance-sessions/{id}/qr-token', [SessionController::class, 'qrToken']);
    $routes->add('POST', '/attendance-sessions/{id}/scan', [SessionController::class, 'scan']);

    $routes->add('GET', '/attendance-records', [RecordController::class, 'index']);
    $routes->add('GET', '/attendance-records/export', [RecordController::class, 'export']);

    $routes->add('GET', '/absence-justifications', [JustificationController::class, 'index']);
    $routes->add('GET', '/absence-justifications/export', [JustificationController::class, 'export']);
    $routes->add('POST', '/absence-justifications', [JustificationController::class, 'store']);
    $routes->add('GET', '/absence-justifications/{id}', [JustificationController::class, 'show']);
    $routes->add('POST', '/absence-justifications/{id}/accept', [JustificationController::class, 'accept']);
    $routes->add('POST', '/absence-justifications/{id}/reject', [JustificationController::class, 'reject']);

    $routes->add('GET', '/incidents', [DisciplineController::class, 'incidents']);
    $routes->add('GET', '/incidents/export', [DisciplineController::class, 'exportIncidents']);
    $routes->add('POST', '/incidents', [DisciplineController::class, 'storeIncident']);
    $routes->add('GET', '/incidents/{id}', [DisciplineController::class, 'showIncident']);
    $routes->add('GET', '/sanctions', [DisciplineController::class, 'sanctions']);
    $routes->add('GET', '/sanctions/export', [DisciplineController::class, 'exportSanctions']);
    $routes->add('POST', '/sanctions', [DisciplineController::class, 'storeSanction']);
    $routes->add('GET', '/sanctions/{id}', [DisciplineController::class, 'showSanction']);
    $routes->add('POST', '/sanctions/{id}/approve', [DisciplineController::class, 'approveSanction']);
    $routes->add('POST', '/sanctions/{id}/reject', [DisciplineController::class, 'rejectSanction']);

    $routes->add('GET', '/authorized-pickups', [PickupController::class, 'index']);
    $routes->add('POST', '/authorized-pickups', [PickupController::class, 'store']);
    $routes->add('POST', '/authorized-pickups/{id}/revoke', [PickupController::class, 'revoke']);

    $routes->add('GET', '/attendance/students/{id}/summary', [AssiduityController::class, 'summary']);
    $routes->add('GET', '/attendance/not-allowed-to-sit', [AssiduityController::class, 'notAllowedToSit']);
};
