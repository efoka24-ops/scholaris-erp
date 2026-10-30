<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Billing\Http\BillingController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/** Routes du module FIN de l'IPES (préfixe /higher, WriteGate avant chaque écriture). */
return static function (HigherRoutes $routes): void {
    $routes->get('/billing/fee-kinds', [BillingController::class, 'feeKinds']);

    $routes->get('/billing/fee-grids', [BillingController::class, 'grids']);
    $routes->post('/billing/fee-grids', [BillingController::class, 'createGrid']);
    $routes->get('/billing/fee-grids/{id}', [BillingController::class, 'showGrid']);
    $routes->post('/billing/fee-grids/{id}/archive', [BillingController::class, 'archiveGrid']);

    $routes->get('/billing/accounts', [BillingController::class, 'accounts']);
    $routes->post('/billing/accounts', [BillingController::class, 'openAccount']);
    $routes->get('/billing/accounts/{id}', [BillingController::class, 'showAccount']);
    $routes->post('/billing/accounts/{id}/payments', [BillingController::class, 'pay']);
    $routes->get('/billing/accounts/{id}/scholarships', [BillingController::class, 'scholarships']);
    $routes->post('/billing/accounts/{id}/scholarships', [BillingController::class, 'requestScholarship']);

    $routes->post('/billing/scholarships/{id}/approve', [BillingController::class, 'approveScholarship']);
    $routes->post('/billing/scholarships/{id}/reject', [BillingController::class, 'rejectScholarship']);

    $routes->post('/billing/payment-plans', [BillingController::class, 'requestPlan']);
    $routes->post('/billing/payment-plans/{id}/approve', [BillingController::class, 'approvePlan']);
    $routes->post('/billing/payment-plans/{id}/reject', [BillingController::class, 'rejectPlan']);

    $routes->get('/billing/sponsors', [BillingController::class, 'sponsors']);
    $routes->post('/billing/sponsors', [BillingController::class, 'createSponsor']);
    $routes->get('/billing/sponsors/{id}', [BillingController::class, 'sponsorStatement']);

    $routes->get('/billing/holds/{personId}', [BillingController::class, 'holds']);
};
