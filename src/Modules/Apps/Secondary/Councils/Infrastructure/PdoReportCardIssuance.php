<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ReportCardIssuance;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Lecture groupée des instantanés de bulletins (une requête par lot de 200 périodes). */
final class PdoReportCardIssuance implements ReportCardIssuance
{
    public function __construct(private readonly Store $store)
    {
    }

    public function issuedFor(string $tenantId, string $establishmentId, array $periodIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($periodIds)), 200) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $statement = $this->store->pdo()->prepare(
                'SELECT structure_id, period_id, MIN(built_at) AS first_issued_at, COUNT(DISTINCT person_id) AS students FROM se_report_snapshots'
                . ' WHERE tenant_id = ? AND establishment_id = ? AND period_id IN (' . $marks . ') GROUP BY structure_id, period_id ORDER BY structure_id, period_id',
            );
            $statement->execute([$tenantId, $establishmentId, ...$chunk]);
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[] = [
                    'structure_id' => (string) $row['structure_id'], 'period_id' => (string) $row['period_id'],
                    'first_issued_at' => (string) $row['first_issued_at'], 'students' => (int) $row['students'],
                ];
            }
        }

        return $out;
    }
}
