<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Staff\Http\Controllers\AssignmentController;
use Scholaris\Modules\Socle\Staff\Http\Controllers\ContractController;
use Scholaris\Modules\Socle\Staff\Http\Controllers\HoursController;
use Scholaris\Modules\Socle\Staff\Http\Controllers\StaffController;
use Scholaris\Modules\Socle\Staff\Http\Controllers\StatementController;

/** Routes du service S10 Personnel enseignant et vacations. Toutes exigent un établissement actif (X-Establishment-Id). */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/staff', [StaffController::class, 'index']);
    $routes->add('POST', '/staff', [StaffController::class, 'store']);
    $routes->add('GET', '/staff/export', [StaffController::class, 'export']);
    $routes->add('GET', '/staff/{id}', [StaffController::class, 'show']);
    $routes->add('PATCH', '/staff/{id}', [StaffController::class, 'update']);
    $routes->add('GET', '/staff/{id}/qualifications', [StaffController::class, 'qualifications']);
    $routes->add('POST', '/staff/{id}/qualifications', [StaffController::class, 'addQualification']);
    $routes->add('DELETE', '/staff/{id}/qualifications/{qualificationId}', [StaffController::class, 'deleteQualification']);
    $routes->add('GET', '/staff/{id}/assignments', [AssignmentController::class, 'index']);
    $routes->add('POST', '/staff/{id}/assignments', [AssignmentController::class, 'store']);
    $routes->add('PATCH', '/staff/{id}/assignments/{assignmentId}', [AssignmentController::class, 'update']);
    $routes->add('DELETE', '/staff/{id}/assignments/{assignmentId}', [AssignmentController::class, 'destroy']);

    $routes->add('GET', '/vacation-rates', [ContractController::class, 'rates']);
    $routes->add('PUT', '/vacation-rates', [ContractController::class, 'putRate']);

    $routes->add('GET', '/vacation-contracts', [ContractController::class, 'index']);
    $routes->add('GET', '/vacation-contracts/export', [ContractController::class, 'export']);
    $routes->add('POST', '/vacation-contracts', [ContractController::class, 'store']);
    $routes->add('GET', '/vacation-contracts/{id}', [ContractController::class, 'show']);
    $routes->add('PATCH', '/vacation-contracts/{id}', [ContractController::class, 'update']);
    $routes->add('POST', '/vacation-contracts/{id}/submit', [ContractController::class, 'submit']);
    $routes->add('POST', '/vacation-contracts/{id}/approve', [ContractController::class, 'approve']);
    $routes->add('POST', '/vacation-contracts/{id}/reject', [ContractController::class, 'reject']);
    $routes->add('POST', '/vacation-contracts/{id}/terminate', [ContractController::class, 'terminate']);

    $routes->add('GET', '/vacation-hours', [HoursController::class, 'index']);
    $routes->add('POST', '/vacation-hours', [HoursController::class, 'store']);
    $routes->add('GET', '/vacation-hours/export', [HoursController::class, 'export']);
    $routes->add('GET', '/vacation-hours/{id}', [HoursController::class, 'show']);
    $routes->add('POST', '/vacation-hours/{id}/sign', [HoursController::class, 'sign']);
    $routes->add('POST', '/vacation-hours/{id}/validate', [HoursController::class, 'validate']);
    $routes->add('POST', '/vacation-hours/{id}/reject', [HoursController::class, 'reject']);

    $routes->add('GET', '/vacation-statements', [StatementController::class, 'index']);
    $routes->add('GET', '/vacation-statements/export', [StatementController::class, 'export']);
    $routes->add('POST', '/vacation-statements', [StatementController::class, 'store']);
    $routes->add('GET', '/vacation-statements/{id}', [StatementController::class, 'show']);
    $routes->add('POST', '/vacation-statements/{id}/submit', [StatementController::class, 'submit']);
    $routes->add('POST', '/vacation-statements/{id}/approve', [StatementController::class, 'approve']);
    $routes->add('POST', '/vacation-statements/{id}/reject', [StatementController::class, 'reject']);
    $routes->add('POST', '/vacation-statements/{id}/pay', [StatementController::class, 'pay']);

    (require __DIR__ . '/routes_leave.php')($routes);
};
