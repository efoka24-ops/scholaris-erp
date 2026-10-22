<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Billing\Http\BillingController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->post('/enrolments/{id}/invoice', [BillingController::class, 'invoice']);
    $routes->get('/children/{id}/balance', [BillingController::class, 'balance']);
    $routes->get('/portal/balance', [BillingController::class, 'familyBalance']);
};
