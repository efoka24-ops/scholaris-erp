<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Portals\Contracts;

use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;

/**
 * Factures ouvertes d'un élève que la famille peut payer depuis le portail (secondary.family.pay). Le socle Billing n'expose pas de
 * liste des factures d'un élève par contrat : le sous-module qui connaît ces factures (Fees, plans de paiement) s'enregistre sous la
 * clé de liste `secondary.family_invoices.<module>` => [MonFournisseur::class]. Sans fournisseur, le portail montre le solde (socle)
 * sans bouton de paiement.
 */
interface FamilyInvoices
{
    /** @return list<InvoiceSummary> factures de l'élève dans l'établissement (le portail ne garde que celles qui ont un reste dû) */
    public function openFor(string $tenantId, string $establishmentId, string $learnerId): array;
}
