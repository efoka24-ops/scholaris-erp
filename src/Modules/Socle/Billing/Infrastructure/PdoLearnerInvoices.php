<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;

final class PdoLearnerInvoices implements LearnerInvoices
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function idsFor(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $statement = $this->connections->pdo()->prepare(
            "SELECT id FROM invoices WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ? AND status <> 'cancelled' ORDER BY created_at, id",
        );
        $statement->execute([$tenantId, $establishmentId, $learnerId]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }
}
