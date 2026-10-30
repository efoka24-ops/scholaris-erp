<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Fees\Http\FeeCatalogController;
use Scholaris\Modules\Apps\Secondary\Fees\Http\FeesController;
use Scholaris\Modules\Apps\Secondary\Fees\Http\PaymentPlanController;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Frais du secondaire (T830 à T832). Vue d'ensemble : noyau. Privé [fees_private] : préparation et plans de paiement. Public
 * [fees_public_catalog] : catalogue des frais autorisés et demandes d'ajout. Grilles, factures et paiements : routes du socle Billing.
 */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/fees/overview', [FeesController::class, 'overview']);

    $private = $routes->module(Modules::FEES_PRIVATE);
    $private->post('/fees/private/setup', [FeesController::class, 'setupPrivate']);
    $private->get('/fees/payment-plans', [PaymentPlanController::class, 'index']);
    $private->post('/fees/payment-plans', [PaymentPlanController::class, 'store']);
    $private->get('/fees/payment-plans/{id}', [PaymentPlanController::class, 'show']);
    $private->post('/fees/payment-plans/{id}/approve', [PaymentPlanController::class, 'approve']);
    $private->post('/fees/payment-plans/{id}/refuse', [PaymentPlanController::class, 'refuse']);
    $private->post('/fees/payment-plans/{id}/cancel', [PaymentPlanController::class, 'cancel']);

    $public = $routes->module(Modules::FEES_PUBLIC_CATALOG);
    $public->get('/fees/catalog', [FeeCatalogController::class, 'index']);
    $public->post('/fees/catalog', [FeeCatalogController::class, 'store']);
    $public->post('/fees/catalog/{id}/retire', [FeeCatalogController::class, 'retire']);
    $public->get('/fees/requests', [FeeCatalogController::class, 'requests']);
    $public->post('/fees/requests', [FeeCatalogController::class, 'storeRequest']);
    $public->post('/fees/requests/{id}/decide', [FeeCatalogController::class, 'decide']);
};
