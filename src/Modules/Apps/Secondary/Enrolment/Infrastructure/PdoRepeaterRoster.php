<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\RepeaterRoster;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Lecture par lots de 500 personnes de `se_student_profiles.repeater`. */
final class PdoRepeaterRoster implements RepeaterRoster
{
    public function __construct(private readonly Store $store)
    {
    }

    public function repeatersAmong(string $tenantId, string $establishmentId, array $personIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($personIds)), 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $statement = $this->store->pdo()->prepare(
                'SELECT person_id FROM se_student_profiles WHERE tenant_id = ? AND establishment_id = ? AND repeater = 1 AND person_id IN (' . $marks . ')',
            );
            $statement->execute([$tenantId, $establishmentId, ...$chunk]);
            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $out[] = is_scalar($id) ? (string) $id : '';
            }
        }

        return $out;
    }
}
