<?php

declare(strict_types=1);

namespace Tests\Unit\Secondary\Discipline;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Secondary\Discipline\Application\AttendanceHours;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;

/**
 * FIX-SEC, défaut 5 : la période par défaut de la discipline s'affichait « trimester 1 » (code anglais du socle). Libellé français
 * par défaut (données enregistrées, SMS) et genre et rang rendus pour que l'écran le traduise. Données fictives.
 */
final class AttendanceHoursPeriodTest extends TestCase
{
    private function hours(): AttendanceHours
    {
        $periods = new class () implements CurrentPeriod {
            public function at(string $tenantId, string $establishmentId, string $date): ?PeriodInfo
            {
                return null;
            }

            public function allAt(string $tenantId, string $establishmentId, string $date): array
            {
                return [
                    new PeriodInfo('annee', 'annee-2026', '2026-2027', 'year', 1, '2026-09-01', '2027-06-30'),
                    new PeriodInfo('trim-1', 'annee-2026', '2026-2027', 'trimester', 1, '2026-09-07', '2026-12-18', 'annee'),
                ];
            }
        };
        $totals = new class () implements AttendanceTotals {
            public function byLearner(string $tenantId, string $establishmentId, string $from, string $to): array
            {
                return [];
            }
        };

        return new AttendanceHours($totals, $periods, new SecondaryClock('2026-10-15'));
    }

    public function testLaPeriodeParDefautPorteUnLibelleFrancaisEtSonGenre(): void
    {
        $period = $this->hours()->period('t', 'e', null, null);
        self::assertSame(['from' => '2026-09-07', 'to' => '2026-12-18', 'label' => 'Trimestre 1', 'kind' => 'trimester', 'ordinal' => 1], $period);
    }

    public function testUnLibelleDonneEstConserveTelQuel(): void
    {
        self::assertSame('Premier trimestre', $this->hours()->period('t', 'e', null, null, 'Premier trimestre')['label']);
        self::assertSame(['Séquence 2', 'Semestre 1', 'Période 3'], [AttendanceHours::label('sequence', 2), AttendanceHours::label('semester', 1), AttendanceHours::label('other', 3)]);
    }
}
