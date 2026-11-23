<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Remediation\Http\RemediationController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Remédiation : registre de la classe et fiches de suivi individualisées. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/classes/{id}/remediation', [RemediationController::class, 'register']);
    $routes->post('/classes/{id}/remediation', [RemediationController::class, 'createSession']);
    $routes->get('/remediation-plans', [RemediationController::class, 'plans']);
    $routes->post('/remediation-plans', [RemediationController::class, 'createPlan']);
    $routes->get('/remediation-plans/{id}', [RemediationController::class, 'plan']);
    $routes->post('/remediation-plans/{id}/reviews', [RemediationController::class, 'review']);
};
