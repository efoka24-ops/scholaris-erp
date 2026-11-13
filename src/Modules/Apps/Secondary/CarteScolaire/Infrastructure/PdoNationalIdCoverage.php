<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Infrastructure;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIdCoverage;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Lecture par lots de 500 personnes de `se_cs_students` (état seulement, jamais le matricule). */
final class PdoNationalIdCoverage implements NationalIdCoverage
{
    public function __construct(private readonly Store $store)
    {
    }

    public function statusesOf(string $tenantId, string $establishmentId, array $personIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($personIds)), 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $statement = $this->store->pdo()->prepare(
                'SELECT person_id, id_status FROM se_cs_students WHERE tenant_id = ? AND establishment_id = ? AND person_id IN (' . $marks . ')',
            );
            $statement->execute([$tenantId, $establishmentId, ...$chunk]);
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[(string) $row['person_id']] = (string) $row['id_status'];
            }
        }

        return $out;
    }
}
