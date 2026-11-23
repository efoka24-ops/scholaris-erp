<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceCalls;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Indicateur territorial « présence des élèves » (C-105, CDC : taux de présence, objectif O4 « les écoles remontent la présence »).
 * Un total par école et par jour, tiré du suivi des appels (`pub_attendance_calls`) : élèves présents et absents, comptés par classe et
 * demi-journée, et une case `reporting=school` (1) pour une école qui a fait au moins un appel ce jour-là. Aucun nom, aucun identifiant.
 * Un jour sans appel ne donne aucune case : l'école n'est pas comptée présente par défaut.
 */
final class PupilPresenceAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly PdoAttendanceCalls $calls)
    {
    }

    public function indicator(): string
    {
        return 'public_schools.pupil_presence';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $totals = $this->calls->totalsOn($tenantId, $establishmentId, substr($referenceDate, 0, 10));
        if ($totals['calls'] === 0) {
            return [];
        }
        $absent = min($totals['absent'], $totals['headcount']);

        return ['reporting=school' => 1, 'state=absent' => $absent, 'state=present' => $totals['headcount'] - $absent];
    }
}
