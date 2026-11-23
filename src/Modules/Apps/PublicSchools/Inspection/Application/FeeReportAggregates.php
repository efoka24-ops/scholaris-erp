<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Inspection\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/** Indicateur territorial « contrôle de la gratuité » (EX-INS-05) : signalements de frais illégaux par état (`state=open|…`). */
final class FeeReportAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function indicator(): string
    {
        return 'public_schools.fee_reports';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $query = $this->connections->pdo()->prepare('SELECT report_state, COUNT(*) AS n FROM pub_fee_reports WHERE tenant_id = ? AND establishment_id = ? AND reported_on <= ? GROUP BY report_state');
        $query->execute([$tenantId, $establishmentId, $referenceDate]);
        $counts = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts['state=' . (string) $row['report_state']] = (int) $row['n'];
        }
        ksort($counts);

        return $counts;
    }
}
