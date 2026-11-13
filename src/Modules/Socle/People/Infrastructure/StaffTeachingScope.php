<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Application\PeopleClock;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Scholaris\Modules\Socle\Scheduling\Contracts\TeacherClasses;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/**
 * Périmètre réel d'un enseignant : ses classes sont celles de ses affectations actives (Staff) et celles de ses créneaux
 * d'emploi du temps en vigueur (Scheduling) ; ses apprenants sont les inscrits actifs de ces classes. Un enseignant sans
 * affectation ni créneau n'a aucun apprenant (refus par défaut).
 */
final class StaffTeachingScope implements TeachingScope
{
    public function __construct(
        private readonly TeacherWorkload $workload,
        private readonly TeacherClasses $timetable,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PeopleClock $clock,
    ) {
    }

    public function learnerIdsOf(string $tenantId, string $teacherPersonId): array
    {
        $structures = $this->timetable->structureIdsOf($tenantId, $teacherPersonId, $this->clock->today());
        foreach ($this->workload->assignmentsOf($tenantId, $teacherPersonId) as $assignment) {
            if ($assignment['structure_id'] !== null) {
                $structures[] = $assignment['structure_id'];
            }
        }

        $learners = [];
        foreach (array_unique($structures) as $structureId) {
            foreach ($this->enrollments->personIdsInStructure($tenantId, $structureId) as $personId) {
                $learners[$personId] = true;
            }
        }

        return array_map('strval', array_keys($learners));
    }
}
