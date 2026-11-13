<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Infrastructure;

use PDO;
use Scholaris\Modules\Apps\Primary\Grades\Application\CompetencySheetService;
use Scholaris\Modules\Apps\Primary\Grades\Contracts\SheetProgress;
use Scholaris\Modules\Apps\Primary\Support\Store;

final class PdoSheetProgress implements SheetProgress
{
    public function __construct(private readonly Store $store)
    {
    }

    public function marksByPeriod(string $tenantId, string $establishmentId, string $classId): array
    {
        $statement = $this->store->pdo()->prepare(
            'SELECT period_id, COUNT(*) AS total FROM ' . CompetencySheetService::MARKS
            . ' WHERE tenant_id = ? AND establishment_id = ? AND class_structure_id = ? GROUP BY period_id',
        );
        $statement->execute([$tenantId, $establishmentId, $classId]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['period_id']] = (int) $row['total'];
        }

        return $out;
    }

    public function statesByPeriod(string $tenantId, string $establishmentId, string $classId): array
    {
        $out = [];
        foreach ($this->store->select(CompetencySheetService::STATES, $tenantId, $establishmentId, ['class_structure_id' => $classId], 'period_id', 1000) as $row) {
            $out[(string) $row['period_id']] = (string) $row['status'];
        }

        return $out;
    }
}
