<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\TeachingAssignments;
use Scholaris\Modules\Apps\Secondary\Timetable\Infrastructure\PdoTeachingAssignments;

/** Liaisons de l'emploi du temps : affectations et créneaux offerts aux autres sous-modules (Syllabus, Discipline, Grades, Staff). */
return [
    TeachingAssignments::class => get(PdoTeachingAssignments::class),
];
