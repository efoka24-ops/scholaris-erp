<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;
use Scholaris\Modules\Apps\Higher\Vacations\Http\VacationController;
use Scholaris\Modules\Apps\Higher\Vacations\Http\VacationWorkflowController;

/**
 * Paiement des vacations (EX-ENS-06/07, RG-ENS-1 à 5, NOT-14, DOC-25, DOC-26) : états mensuels, visa de l'école, approbation de la
 * direction, paiement par le comptable, « Mes vacations ». Routes enregistrées par HigherRoutes (préfixe /higher, WriteGate).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/vacations/statements', [VacationController::class, 'index']);
    $routes->post('/vacations/statements', [VacationController::class, 'prepare']);
    $routes->get('/vacations/to-prepare', [VacationController::class, 'toPrepare']);
    $routes->get('/vacations/mine', [VacationController::class, 'mine']);
    $routes->get('/vacations/statements/{id}', [VacationController::class, 'show']);
    $routes->post('/vacations/statements/{id}/validate-school', [VacationWorkflowController::class, 'validateSchool']);
    $routes->post('/vacations/statements/{id}/approve', [VacationWorkflowController::class, 'approve']);
    $routes->post('/vacations/statements/{id}/reject', [VacationWorkflowController::class, 'reject']);
    $routes->post('/vacations/statements/{id}/pay', [VacationWorkflowController::class, 'pay']);
    $routes->post('/vacations/statements/{id}/contract', [VacationWorkflowController::class, 'contract']);
    $routes->post('/vacations/statements/{id}/statement-document', [VacationWorkflowController::class, 'statementDocument']);
    $routes->post('/vacations/statements/{id}/payslip', [VacationWorkflowController::class, 'payslip']);
};
