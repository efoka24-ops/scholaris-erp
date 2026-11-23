<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Soldes de tous les apprenants d'un établissement en une seule lecture (tableaux de bord, lanceur). Contrat distinct
 * de DebtorDirectory pour ne pas imposer de nouvelle méthode à ses implémentations existantes. Fourni par Billing.
 */
interface EstablishmentBalances
{
    /** @return array<string, int> solde restant dû en XAF par identifiant d'apprenant (apprenants facturés seulement) */
    public function balancesXaf(string $tenantId, string $establishmentId): array;
}
