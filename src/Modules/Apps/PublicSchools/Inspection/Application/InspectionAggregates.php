<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Inspection\Application;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Indicateur territorial « contrôle des effectifs sur place » (objectif O1 : écart entre effectifs déclarés et présents lors d'un
 * contrôle, EX-INS-04) : cases `measure=counted` (élèves comptés) et `measure=registry` (effectif du registre) des visites faites
 * dans l'année qui précède la date de référence, plus `measure=visits`. Aucun nom.
 */
final class InspectionAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function indicator(): string
    {
        return 'public_schools.inspection_headcount';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $from = (new DateTimeImmutable($referenceDate))->modify('-1 year')->format('Y-m-d');
        $query = $this->connections->pdo()->prepare("SELECT COUNT(*) AS visits, COALESCE(SUM(counted_present), 0) AS counted, COALESCE(SUM(registry_count), 0) AS registry FROM pub_visits WHERE tenant_id = ? AND establishment_id = ? AND visit_state = 'done' AND done_on > ? AND done_on <= ? AND counted_present IS NOT NULL AND registry_count IS NOT NULL");
        $query->execute([$tenantId, $establishmentId, $from, $referenceDate]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['visits'] === 0) {
            return [];
        }

        return ['measure=counted' => (int) $row['counted'], 'measure=registry' => (int) $row['registry'], 'measure=visits' => (int) $row['visits']];
    }
}
