<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;

/**
 * Période en cours lue dans les tables `periods` et `academic_years` du client et de l'établissement demandés.
 * Le trimestre ou le semestre l'emporte sur la séquence (qui n'est qu'une subdivision) ; à défaut, la séquence.
 */
final class PdoCurrentPeriod implements CurrentPeriod
{
    private const PRIORITY = ['semester' => 0, 'trimester' => 0, 'sequence' => 1];

    public function __construct(private readonly Repositories $repositories)
    {
    }

    public function at(string $tenantId, string $establishmentId, string $date): ?PeriodInfo
    {
        $day = substr($date, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }
        $covering = array_values(array_filter(
            $this->repositories->explicit('periods', $tenantId, $establishmentId)->all(),
            static fn (array $row): bool => substr((string) $row['starts_on'], 0, 10) <= $day && $day <= substr((string) $row['ends_on'], 0, 10),
        ));
        if ($covering === []) {
            return null;
        }
        usort($covering, static fn (array $a, array $b): int => [self::PRIORITY[(string) $a['kind']] ?? 2, (string) $b['starts_on']] <=> [self::PRIORITY[(string) $b['kind']] ?? 2, (string) $a['starts_on']]);
        $row = $covering[0];

        $year = $this->repositories->explicit('academic_years', $tenantId, $establishmentId)->find((string) $row['academic_year_id']);

        return new PeriodInfo(
            (string) $row['id'],
            (string) $row['academic_year_id'],
            $year === null ? null : (string) $year['label'],
            (string) $row['kind'],
            (int) $row['ordinal'],
            (string) $row['starts_on'],
            (string) $row['ends_on'],
        );
    }
}
