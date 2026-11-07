<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Staff\Http\Controllers\LeaveController;

/** Routes des congés et absences (T129), chargées par routes.php. Établissement actif exigé. */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/leave-requests', [LeaveController::class, 'index']);
    $routes->add('GET', '/leave-requests/export', [LeaveController::class, 'export']);
    $routes->add('POST', '/leave-requests', [LeaveController::class, 'store']);
    $routes->add('GET', '/leave-requests/{id}', [LeaveController::class, 'show']);
    $routes->add('POST', '/leave-requests/{id}/approve', [LeaveController::class, 'approve']);
    $routes->add('POST', '/leave-requests/{id}/reject', [LeaveController::class, 'reject']);
    $routes->add('POST', '/leave-requests/{id}/cancel', [LeaveController::class, 'cancel']);
    $routes->add('GET', '/leave-calendar', [LeaveController::class, 'calendar']);
    $routes->add('GET', '/leave-balances', [LeaveController::class, 'balances']);
    $routes->add('PUT', '/leave-balances', [LeaveController::class, 'putBalance']);
};
