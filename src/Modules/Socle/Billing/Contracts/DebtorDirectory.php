<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/** Situation financière exposée aux autres modules (ciblage « impayés », portails, blocages). Fourni par Billing (S04). */
interface DebtorDirectory
{
    /** @return list<string> identifiants des apprenants ayant un solde en retard dans cet établissement */
    public function learnerIdsInArrears(string $tenantId, string $establishmentId): array;

    /** Solde restant dû, en XAF (entier), par apprenant dans un établissement. */
    public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int;

    /** Vrai si un blocage financier paramétré s'applique (résultats, documents, réinscription) ; jamais l'accès aux cours. */
    public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool;
}
