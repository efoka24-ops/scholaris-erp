<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\PublicSchools\Contracts\CensusSectionProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Section « infrastructures » du questionnaire statistique (EX-STA-01, T652) et indicateur territorial des salles utilisées
 * (élèves par salle, T654) : dernière version de l'année (close de préférence) de `pub_infrastructure_years` et ses salles.
 */
final class InfrastructureCensusSection implements CensusSectionProvider, TerritoryAggregateProvider
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function sectionKey(): string
    {
        return 'infrastructure';
    }

    public function title(string $locale): string
    {
        return $locale === 'en' ? 'Infrastructure' : 'Infrastructures';
    }

    public function values(string $tenantId, string $establishmentId, string $yearLabel): array
    {
        $year = $this->latest($tenantId, $establishmentId, $yearLabel);
        if ($year === null) {
            return ['classrooms_in_use' => null, 'classrooms_total' => null, 'desks' => null, 'water_points' => null, 'latrines_girls' => null, 'latrines_boys' => null, 'electricity' => null];
        }
        [$total, $inUse] = $this->rooms($tenantId, (string) $year['id']);

        return [
            'classrooms_in_use' => $inUse, 'classrooms_total' => $total, 'desks' => (int) $year['desks_count'], 'water_points' => (int) $year['water_points'],
            'latrines_girls' => (int) $year['latrines_girls'], 'latrines_boys' => (int) $year['latrines_boys'], 'electricity' => (int) $year['has_electricity'],
        ];
    }

    public function controls(string $tenantId, string $establishmentId, string $yearLabel, array $values): array
    {
        $anomalies = [];
        if ($values['classrooms_in_use'] === null) {
            $anomalies[] = 'infrastructure.missing_year';
        } elseif (is_int($values['classrooms_total']) && is_int($values['classrooms_in_use']) && $values['classrooms_in_use'] > $values['classrooms_total']) {
            $anomalies[] = 'infrastructure.rooms_in_use_above_total';
        }

        return $anomalies;
    }

    public function indicator(): string
    {
        return 'public_schools.classrooms_in_use';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $query = $this->connections->pdo()->prepare('SELECT id FROM pub_infrastructure_years WHERE tenant_id = ? AND establishment_id = ? ORDER BY year_label DESC, version DESC LIMIT 1');
        $query->execute([$tenantId, $establishmentId]);
        $id = $query->fetchColumn();
        if (!is_string($id)) {
            return [];
        }
        [, $inUse] = $this->rooms($tenantId, $id);

        return ['rooms=in_use' => $inUse];
    }

    /** @return array<string, mixed>|null */
    private function latest(string $tenantId, string $establishmentId, string $yearLabel): ?array
    {
        $query = $this->connections->pdo()->prepare("SELECT * FROM pub_infrastructure_years WHERE tenant_id = ? AND establishment_id = ? AND year_label = ? ORDER BY CASE WHEN state = 'closed' THEN 0 ELSE 1 END, version DESC LIMIT 1");
        $query->execute([$tenantId, $establishmentId, $yearLabel]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array{0: int, 1: int} salles au total et salles utilisées */
    private function rooms(string $tenantId, string $yearId): array
    {
        $query = $this->connections->pdo()->prepare('SELECT COUNT(*) AS total, SUM(in_use) AS used FROM pub_classrooms WHERE tenant_id = ? AND infrastructure_year_id = ?');
        $query->execute([$tenantId, $yearId]);
        $row = (array) $query->fetch(PDO::FETCH_ASSOC);

        return [(int) ($row['total'] ?? 0), (int) ($row['used'] ?? 0)];
    }
}
