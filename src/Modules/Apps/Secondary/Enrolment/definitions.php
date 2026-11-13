<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Enrolment\Application\SecondaryEnrolmentGuard;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\StudentRegistry;
use Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure\EnrolmentStudentFacts;
use Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure\PdoStudentRegistry;

/**
 * Liaisons des inscriptions : dossiers des élèves offerts aux autres sous-modules, garde d'inscription enregistrée auprès du socle
 * (D3), faits d'élève (matricule) pour les documents et portails.
 */
return [
    StudentRegistry::class => get(PdoStudentRegistry::class),
    SecondaryEnrolmentGuard::CONTAINER_KEY => [SecondaryEnrolmentGuard::class],
    'secondary.student_facts.enrolment' => [EnrolmentStudentFacts::class],
];
