<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentClock;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\HigherPromotionPlanner;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\MatriculeService;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\StudentService;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\ProgressionDecisionRecorder;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\PdoStudentDirectory;

/**
 * Liaisons des inscriptions (fusionnées par Higher/definitions.php) : contrats offerts aux autres sous-modules Higher, matricule
 * IPES fourni au socle (D5, `people.enrollment_number.providers.higher`), planificateur de passage remplaçant YearPromotion pour
 * les établissements `higher` (D8). Les modèles DOC-04 et DOC-05 sont déclarés par le catalogue Higher\Documents.
 */
return [
    StudentDirectory::class => get(PdoStudentDirectory::class),
    ProgressionDecisionRecorder::class => get(StudentService::class),
    EnrolmentClock::class => autowire(),
    MatriculeService::CONTAINER_KEY => [MatriculeService::class],
    HigherPromotionPlanner::CONTAINER_KEY => [HigherPromotionPlanner::class],
];
