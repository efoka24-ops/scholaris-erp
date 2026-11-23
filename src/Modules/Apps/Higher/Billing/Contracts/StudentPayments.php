<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Contracts;

/** Paiement en ligne (portail étudiant ou garant) d'un compte de scolarité de la personne, par mobile money. */
interface StudentPayments
{
    /**
     * @return array{payment: array<string, mixed>, account: array<string, mixed>} paiement (en attente de l'opérateur) et compte à jour
     * @throws \Scholaris\Core\Http\HttpException 404 compte d'une autre personne, 422 canal ou montant invalide
     */
    public function requestMobilePayment(string $tenantId, string $personId, string $accountId, int $amountXaf, string $channel, string $payerPhone, string $idempotencyKey, string $actorId): array;
}
