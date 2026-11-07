<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Canteen\Application;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Indicateur territorial « campagnes de santé scolaire » (EX-CAN-02) : enfants traités par type de campagne et par sexe, dans l'année
 * qui précède la date de référence (cases `kind=<type>;sex=F|M`), en effectifs seulement.
 */
final class HealthAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function indicator(): string
    {
        return 'public_schools.health_campaigns';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $from = (new DateTimeImmutable($referenceDate))->modify('-1 year')->format('Y-m-d');
        $query = $this->connections->pdo()->prepare('SELECT campaign_kind, SUM(girls_count) AS girls, SUM(boys_count) AS boys FROM pub_health_campaigns WHERE tenant_id = ? AND establishment_id = ? AND held_on > ? AND held_on <= ? GROUP BY campaign_kind');
        $query->execute([$tenantId, $establishmentId, $from, $referenceDate]);
        $counts = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts['kind=' . (string) $row['campaign_kind'] . ';sex=F'] = (int) $row['girls'];
            $counts['kind=' . (string) $row['campaign_kind'] . ';sex=M'] = (int) $row['boys'];
        }
        ksort($counts);

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }
}
