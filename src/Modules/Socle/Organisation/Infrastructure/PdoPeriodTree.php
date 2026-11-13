<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodTree;

/** Arbre des périodes lu dans `periods` et `academic_years` du client et de l'établissement demandés. */
final class PdoPeriodTree implements PeriodTree
{
    private const DEPTH = ['year' => 0, 'semester' => 1, 'trimester' => 1, 'sequence' => 2];

    public function __construct(private readonly Repositories $repositories)
    {
    }

    public function find(string $tenantId, string $establishmentId, string $periodId): ?PeriodInfo
    {
        $row = $this->repositories->explicit('periods', $tenantId, $establishmentId)->find($periodId);

        return $row === null ? null : $this->info($tenantId, $establishmentId, $row);
    }

    public function ofYear(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $rows = array_values(array_filter(
            $this->repositories->explicit('periods', $tenantId, $establishmentId)->all(),
            static fn (array $row): bool => (string) $row['academic_year_id'] === $academicYearId,
        ));
        usort($rows, static fn (array $a, array $b): int => [substr((string) $a['starts_on'], 0, 10), self::DEPTH[(string) $a['kind']] ?? 3, (int) $a['ordinal']]
            <=> [substr((string) $b['starts_on'], 0, 10), self::DEPTH[(string) $b['kind']] ?? 3, (int) $b['ordinal']]);

        return array_map(fn (array $row): PeriodInfo => $this->info($tenantId, $establishmentId, $row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function info(string $tenantId, string $establishmentId, array $row): PeriodInfo
    {
        $year = $this->repositories->explicit('academic_years', $tenantId, $establishmentId)->find((string) $row['academic_year_id']);

        return new PeriodInfo(
            (string) $row['id'],
            (string) $row['academic_year_id'],
            $year === null ? null : (string) $year['label'],
            (string) $row['kind'],
            (int) $row['ordinal'],
            (string) $row['starts_on'],
            (string) $row['ends_on'],
            isset($row['parent_id']) && $row['parent_id'] !== '' ? (string) $row['parent_id'] : null,
        );
    }
}
