<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Référence de paiement famille (ex. FAM-0587) d'un garant, en LECTURE seule (D-BUL, 12 novembre 2026) : pour l'afficher au portail
 * parent ou sur la fiche de l'élève. N'émet jamais de référence (l'émission reste POST /payment-references). L'appelant borne le
 * garant à son périmètre (le parent connecté, ou les garants d'un élève lisible). Fourni par Billing.
 */
interface FamilyPaymentReferences
{
    /** Référence du garant dans ce client, ou null s'il n'en a pas encore. */
    public function referenceOf(string $tenantId, string $guarantorId): ?string;
}
