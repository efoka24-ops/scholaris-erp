<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Contracts;

/**
 * Respect du catalogue des frais autorisés (contrat de LECTURE interne, fourni par Fees, ajouté pour l'objectif O5 des tableaux de
 * bord E4-A) : comptes des demandes d'ajout au catalogue créées entre deux jours inclus. `blocked` = frais refusés hors catalogue par
 * la garde (origine `guard`, une demande tant qu'elle attend) ; les autres comptes portent sur toutes les demandes. Aucun montant
 * nominatif ; borné au client et à l'établissement.
 */
interface FeeCompliance
{
    /** @return array{blocked: int, pending: int, accepted: int, refused: int} */
    public function requestCounts(string $tenantId, string $establishmentId, string $from, string $to): array;
}
