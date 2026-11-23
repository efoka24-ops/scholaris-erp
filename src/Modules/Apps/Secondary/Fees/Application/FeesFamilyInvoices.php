<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Modules\Apps\Secondary\Portals\Contracts\FamilyInvoices;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;

/**
 * Factures ouvertes d'un élève pour le portail parent (contrat Portals\Contracts\FamilyInvoices), enregistré sous
 * `secondary.family_invoices.fees` : le parent paie une facture précise et DOC-28/29 n'exigent plus l'identifiant saisi à la main.
 * Lecture par les contrats du socle Billing seulement (LearnerInvoices puis InvoiceIssuer::find) ; factures soldées écartées.
 */
final class FeesFamilyInvoices implements FamilyInvoices
{
    public function __construct(private readonly LearnerInvoices $learnerInvoices, private readonly InvoiceIssuer $invoices)
    {
    }

    public function openFor(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $open = [];
        foreach ($this->learnerInvoices->idsFor($tenantId, $establishmentId, $learnerId) as $invoiceId) {
            $invoice = $this->invoices->find($tenantId, $establishmentId, $invoiceId);
            if ($invoice instanceof InvoiceSummary && $invoice->subjectId === $learnerId && $invoice->balanceXaf > 0) {
                $open[] = $invoice;
            }
        }

        return $open;
    }
}
