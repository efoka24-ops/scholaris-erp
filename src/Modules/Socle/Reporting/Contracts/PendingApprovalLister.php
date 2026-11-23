<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Contrat ENTRANT local : identifiants des demandes d'approbation que cette personne peut décider.
 * Branché par défaut sur Audit\Contracts\PendingApprovals (adaptateur AuditPendingApprovals).
 */
interface PendingApprovalLister
{
    /** @return list<string> identifiants de demandes candidates (statut revérifié via Approvals::find) */
    public function candidateIds(string $tenantId, string $userId): array;
}
