<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\PendingApprovals;

final class PdoPendingApprovals implements PendingApprovals
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pendingIdsFor(string $tenantId, string $userId, int $limit = 100): array
    {
        $statement = $this->connections->pdo()->prepare(sprintf(
            'SELECT id FROM approvals WHERE tenant_id = ? AND status = ? AND requested_by <> ? ORDER BY created_at, id LIMIT %d',
            max(1, min(500, $limit)),
        ));
        $statement->execute([$tenantId, Approvals::PENDING, $userId]);

        return array_values(array_map(static fn (array $row): string => (string) $row['id'], $statement->fetchAll()));
    }
}
