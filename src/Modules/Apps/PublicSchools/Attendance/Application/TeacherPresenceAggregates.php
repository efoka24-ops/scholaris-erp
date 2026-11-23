<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoTeacherPresence;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Indicateur territorial « présence des enseignants » (C-105) : nombre d'enseignants déclarés ce jour-là par type de présence
 * (`presence=present|absent|permission|mission`), tiré des déclarations du directeur. Aucun nom, aucun identifiant de personnel.
 */
final class TeacherPresenceAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly PdoTeacherPresence $presence)
    {
    }

    public function indicator(): string
    {
        return 'public_schools.teacher_presence';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $counts = [];
        foreach ($this->presence->countsByPresence($tenantId, $establishmentId, substr($referenceDate, 0, 10)) as $presence => $total) {
            $counts['presence=' . $presence] = $total;
        }

        return $counts;
    }
}
