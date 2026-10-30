<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\PendingApprovals;
use Scholaris\Modules\Socle\Reporting\Contracts\PendingApprovalLister;

/** Adaptateur : les demandes en attente viennent du contrat Audit. */
final class AuditPendingApprovals implements PendingApprovalLister
{
    public function __construct(private readonly PendingApprovals $pending)
    {
    }

    public function candidateIds(string $tenantId, string $userId): array
    {
        return $this->pending->pendingIdsFor($tenantId, $userId, 100);
    }
}
