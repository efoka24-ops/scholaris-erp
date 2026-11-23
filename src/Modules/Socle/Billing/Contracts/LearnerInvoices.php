<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Factures d'un apprenant dans un établissement (identifiants seulement, détail par InvoiceIssuer::find) : portails et applications
 * qui doivent proposer le paiement d'une facture précise. Contrat distinct pour n'imposer aucune méthode aux contrats existants.
 */
interface LearnerInvoices
{
    /** @return list<string> identifiants des factures de l'apprenant (hors annulées), de la plus ancienne à la plus récente */
    public function idsFor(string $tenantId, string $establishmentId, string $learnerId): array;
}
