<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Contrat d'encaissement pour les applications (D4) : paiement d'une facture (y compris une facture libre de candidat),
 * avec affectation selon `billing.allocation_rule`, reçu numéroté et audit, dans une seule transaction.
 *
 * Erreurs (HttpException) : 404 facture inconnue, 409 caisse fermée, 422 canal ou montant invalide.
 */
interface PaymentRequester
{
    public function request(PaymentOrder $order): PaymentOutcome;

    public function status(string $tenantId, string $establishmentId, string $paymentId): ?PaymentOutcome;
}
