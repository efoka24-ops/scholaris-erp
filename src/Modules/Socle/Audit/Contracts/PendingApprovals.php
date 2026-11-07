<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/** Liste des demandes d'approbation en attente qu'une personne pourrait décider. Complète Approvals sans le modifier. */
interface PendingApprovals
{
    /**
     * Demandes en attente du client, hors celles que l'utilisateur a lui-même demandées (il ne peut pas les valider).
     * Les plus anciennes d'abord.
     *
     * @return list<string> identifiants de demandes
     */
    public function pendingIdsFor(string $tenantId, string $userId, int $limit = 100): array;
}
