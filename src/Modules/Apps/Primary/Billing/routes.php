<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Billing\Http\BillingController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Frais du primaire : natures et facture d'inscription. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/billing/fee-kinds', [BillingController::class, 'feeKinds']);
    $routes->post('/enrolments/{id}/invoice', [BillingController::class, 'invoice']);
};
