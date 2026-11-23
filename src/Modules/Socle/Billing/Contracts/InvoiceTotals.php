<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Totaux de plusieurs factures en une lecture par lot (tableaux de bord, statistiques) : net, payé, reste dû et part échue à une
 * date. Contrat distinct d'InvoiceIssuer pour ne pas imposer de méthode aux implémentations existantes. Fourni par Billing ;
 * borné au client et à l'établissement ; une facture inconnue est absente du résultat.
 */
interface InvoiceTotals
{
    /**
     * @param list<string> $invoiceIds
     * @return array<string, array{net_xaf: int, paid_xaf: int, balance_xaf: int, overdue_xaf: int}> par identifiant de facture ; `overdue_xaf` : tranches échues strictement avant `$today` et non soldées
     */
    public function totals(string $tenantId, string $establishmentId, array $invoiceIds, string $today): array;
}
