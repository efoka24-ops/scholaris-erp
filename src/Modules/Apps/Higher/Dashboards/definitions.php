<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicLabels;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\PdoAcademicLabels;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\AdmissionStatistics;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\PdoAdmissionStatistics;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\AssiduityStatistics;
use Scholaris\Modules\Apps\Higher\Attendance\Infrastructure\PdoAssiduityStatistics;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\CollectionStatistics;
use Scholaris\Modules\Apps\Higher\Billing\Infrastructure\PdoCollectionStatistics;
use Scholaris\Modules\Apps\Higher\Dashboards\Application\DashboardClock;
use Scholaris\Modules\Apps\Higher\Dashboards\Documents\DashboardDocumentTypes;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentStatistics;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\PdoEnrolmentStatistics;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradingStatistics;
use Scholaris\Modules\Apps\Higher\Grades\Infrastructure\PdoGradingStatistics;
use Scholaris\Modules\Apps\Higher\NationalExams\Contracts\NationalResultStatistics;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\PdoNationalResultStatistics;
use Scholaris\Modules\Apps\Higher\Vacations\Contracts\VacationStatistics;
use Scholaris\Modules\Apps\Higher\Vacations\Infrastructure\PdoVacationStatistics;

/**
 * Liaisons des tableaux de bord (PIL) : contrats d'agrégation offerts par chaque module Higher (une requête GROUP BY par tableau),
 * libellés académiques, lecteur en lot des caractéristiques de personnes (People) et type de document DOC-32 (statistiques MINESUP).
 * Les liaisons sont ici pour ne pas modifier les definitions.php des autres modules.
 */
return [
    AcademicLabels::class => autowire(PdoAcademicLabels::class),
    AdmissionStatistics::class => autowire(PdoAdmissionStatistics::class),
    EnrolmentStatistics::class => autowire(PdoEnrolmentStatistics::class),
    CollectionStatistics::class => autowire(PdoCollectionStatistics::class),
    GradingStatistics::class => autowire(PdoGradingStatistics::class),
    AssiduityStatistics::class => autowire(PdoAssiduityStatistics::class),
    VacationStatistics::class => autowire(PdoVacationStatistics::class),
    NationalResultStatistics::class => autowire(PdoNationalResultStatistics::class),
    DashboardClock::class => autowire(),
    'higher.document_types.dashboards' => [DashboardDocumentTypes::class],
];
