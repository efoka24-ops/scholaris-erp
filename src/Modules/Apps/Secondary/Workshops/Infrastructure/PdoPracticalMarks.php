<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Workshops\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Workshops\Contracts\PracticalMarks;

/** Lecture des évaluations pratiques des séances d'une classe entre deux dates (incluses), bornée au client et à l'établissement. */
final class PdoPracticalMarks implements PracticalMarks
{
    public function __construct(private readonly Store $store)
    {
    }

    public function forClass(string $tenantId, string $establishmentId, string $structureId, string $from, string $to): array
    {
        $statement = $this->store->pdo()->prepare(
            'SELECT e.person_id AS person_id, COUNT(*) AS evaluations, SUM(e.final_score) AS points, SUM(p.base_score) AS out_of'
            . ' FROM se_practical_evaluations e'
            . ' JOIN se_workshop_sessions s ON s.id = e.session_id AND s.tenant_id = e.tenant_id AND s.establishment_id = e.establishment_id'
            . ' JOIN se_practical_sheets p ON p.id = e.sheet_id AND p.tenant_id = e.tenant_id AND p.establishment_id = e.establishment_id'
            . ' WHERE e.tenant_id = ? AND e.establishment_id = ? AND s.structure_id = ? AND s.session_date >= ? AND s.session_date <= ?'
            . ' GROUP BY e.person_id',
        );
        $statement->execute([$tenantId, $establishmentId, $structureId, substr($from, 0, 10), substr($to, 0, 10)]);
        $out = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['person_id']] = [
                'evaluations' => (int) $row['evaluations'],
                'points' => round((float) $row['points'], 2),
                'out_of' => round((float) $row['out_of'], 2),
            ];
        }

        return $out;
    }
}
