<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Application;

use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;

/** Élèves inscrits d'une école à une date, par classe (contrats seulement), pour les indicateurs territoriaux. */
final class SchoolPopulation
{
    public function __construct(private readonly ClassRoster $classes, private readonly CurrentPeriod $periods)
    {
    }

    /** @return array<string, array{level: string, pupils: list<string>}> classe => niveau principal et élèves */
    public function at(string $tenantId, string $establishmentId, string $date): array
    {
        $day = substr($date, 0, 10);
        $period = $this->periods->at($tenantId, $establishmentId, $day);
        if ($period === null) {
            return [];
        }
        $classes = [];
        foreach ($this->classes->classesOf($tenantId, $establishmentId, $period->academicYearId) as $class) {
            $classes[$class->structureId] = ['level' => $class->levelCodes[0] ?? 'unknown', 'pupils' => $this->classes->pupilIdsIn($tenantId, $establishmentId, $class->structureId, $day)];
        }

        return $classes;
    }
}
