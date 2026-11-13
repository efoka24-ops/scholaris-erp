<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Billing\Http\CashSessionController;
use Scholaris\Modules\Socle\Billing\Http\ExportController;
use Scholaris\Modules\Socle\Billing\Http\FeeItemController;
use Scholaris\Modules\Socle\Billing\Http\FeeScheduleController;
use Scholaris\Modules\Socle\Billing\Http\InvoiceController;
use Scholaris\Modules\Socle\Billing\Http\PaymentController;
use Scholaris\Modules\Socle\Billing\Http\PaymentReferenceController;
use Scholaris\Modules\Socle\Billing\Http\ReceiptController;
use Scholaris\Modules\Socle\Billing\Http\ReconciliationController;
use Scholaris\Modules\Socle\Billing\Http\ReversalController;
use Scholaris\Modules\Socle\Billing\Http\WebhookController;

/** Routes du module Billing (S04). Le préfixe /api/v1 est ajouté par le registre ; toutes exigent X-Establishment-Id sauf le webhook. */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/fee-items', [FeeItemController::class, 'index']);
    $routes->add('POST', '/fee-items', [FeeItemController::class, 'store']);
    $routes->add('GET', '/fee-items/{id}', [FeeItemController::class, 'show']);
    $routes->add('PATCH', '/fee-items/{id}', [FeeItemController::class, 'update']);

    $routes->add('GET', '/fee-schedules', [FeeScheduleController::class, 'index']);
    $routes->add('POST', '/fee-schedules', [FeeScheduleController::class, 'store']);
    $routes->add('GET', '/fee-schedules/{id}', [FeeScheduleController::class, 'show']);

    $routes->add('GET', '/invoices/export', [InvoiceController::class, 'export']);
    $routes->add('GET', '/invoices', [InvoiceController::class, 'index']);
    $routes->add('POST', '/invoices', [InvoiceController::class, 'store']);
    $routes->add('GET', '/invoices/{id}', [InvoiceController::class, 'show']);
    $routes->add('POST', '/invoices/{id}/discounts', [InvoiceController::class, 'addDiscount']);
    $routes->add('POST', '/discounts/{id}/approve', [InvoiceController::class, 'approveDiscount']);

    $routes->add('GET', '/payments/export', [PaymentController::class, 'export']);
    $routes->add('GET', '/payments', [PaymentController::class, 'index']);
    $routes->add('POST', '/payments', [PaymentController::class, 'store']);
    $routes->add('GET', '/payments/{id}', [PaymentController::class, 'show']);
    $routes->add('POST', '/cash/receipts', [PaymentController::class, 'cashReceipt']);

    $routes->add('GET', '/receipts/export', [ReceiptController::class, 'export']);
    $routes->add('GET', '/receipts', [ReceiptController::class, 'index']);
    $routes->add('GET', '/receipts/{id}', [ReceiptController::class, 'show']);
    $routes->add('GET', '/receipts/{id}/pdf', [ReceiptController::class, 'pdf']);

    $routes->add('GET', '/cash-sessions', [CashSessionController::class, 'index']);
    $routes->add('POST', '/cash-sessions', [CashSessionController::class, 'open']);
    $routes->add('GET', '/cash-sessions/{id}', [CashSessionController::class, 'show']);
    $routes->add('POST', '/cash-sessions/{id}/close', [CashSessionController::class, 'close']);
    $routes->add('POST', '/cash-sessions/{id}/deposits', [CashSessionController::class, 'deposit']);

    $routes->add('GET', '/reversals', [ReversalController::class, 'index']);
    $routes->add('POST', '/reversals', [ReversalController::class, 'store']);
    $routes->add('GET', '/reversals/{id}', [ReversalController::class, 'show']);
    $routes->add('POST', '/reversals/{id}/approve', [ReversalController::class, 'approve']);
    $routes->add('POST', '/reversals/{id}/reject', [ReversalController::class, 'reject']);

    $routes->add('GET', '/reconciliations', [ReconciliationController::class, 'index']);
    $routes->add('POST', '/reconciliations', [ReconciliationController::class, 'store']);
    $routes->add('GET', '/reconciliations/{id}', [ReconciliationController::class, 'show']);

    $routes->add('GET', '/payment-references', [PaymentReferenceController::class, 'index']);
    $routes->add('POST', '/payment-references', [PaymentReferenceController::class, 'store']);
    $routes->add('GET', '/billing/families/{reference}/balance', [PaymentReferenceController::class, 'balance']);
    $routes->add('GET', '/billing/exports/{kind}', [ExportController::class, 'export']);

    $routes->add('POST', '/webhooks/payments/{provider}', [WebhookController::class, 'receive'], ['public' => true]);
};
