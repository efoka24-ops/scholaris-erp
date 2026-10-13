<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Reporting\Contracts\PendingApprovalLister;

/** Doublure de PendingApprovalLister. */
final class PendingIds implements PendingApprovalLister
{
    /** @var array<string, list<string>> */
    public array $ids = [];

    public function candidateIds(string $tenantId, string $userId): array
    {
        return $this->ids[$tenantId . '|' . $userId] ?? [];
    }
}
