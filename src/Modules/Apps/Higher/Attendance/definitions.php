<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Attendance\Application\AssiduityService;
use Scholaris\Modules\Apps\Higher\Attendance\Application\AttendanceClock;
use Scholaris\Modules\Apps\Higher\Attendance\Application\EnrolmentExclusion;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\NationalExamAttendance;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\SittingEligibility;
use Scholaris\Modules\Apps\Higher\Attendance\Documents\AttendanceDocumentTypes;
use Scholaris\Modules\Apps\Higher\Attendance\Infrastructure\PendingEnrolmentExclusion;

/**
 * Liaisons des présences et de la discipline : autorisation de composer (Grades, Teaching), présence des examens nationaux
 * (NationalExams), exclusion de l'inscription (port, en attente d'un contrat d'Enrolment), type DOC-31.
 */
return [
    SittingEligibility::class => get(AssiduityService::class),
    NationalExamAttendance::class => get(AssiduityService::class),
    AttendanceClock::class => autowire(),
    EnrolmentExclusion::class => get(PendingEnrolmentExclusion::class),
    'higher.document_types.attendance' => [AttendanceDocumentTypes::class],
];
