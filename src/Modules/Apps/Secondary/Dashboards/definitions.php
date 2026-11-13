<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIdCoverage;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Infrastructure\PdoNationalIdCoverage;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ReportCardIssuance;
use Scholaris\Modules\Apps\Secondary\Councils\Infrastructure\PdoReportCardIssuance;
use Scholaris\Modules\Apps\Secondary\Dashboards\Application\NetworkDashboard;
use Scholaris\Modules\Apps\Secondary\Dashboards\Application\SecondaryIndicators;
use Scholaris\Modules\Apps\Secondary\Dashboards\Domain\DashboardExportTypes;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\RepeaterRoster;
use Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure\PdoRepeaterRoster;
use Scholaris\Modules\Apps\Secondary\Fees\Contracts\FeeCompliance;
use Scholaris\Modules\Apps\Secondary\Fees\Infrastructure\PdoFeeCompliance;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamOutcomes;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Infrastructure\PdoExamOutcomes;
use Scholaris\Modules\Socle\Reporting\Contracts\ConfiguredDashboard;

/**
 * Liaisons du pilotage. Les contrats de LECTURE ajoutés en E4-A dans leurs sous-modules propriétaires (nouveaux fichiers, sans
 * modifier leurs definitions.php) sont liés ici : Councils ReportCardIssuance, Fees FeeCompliance, CarteScolaire
 * NationalIdCoverage, OfficialExams ExamOutcomes, Enrolment RepeaterRoster. Points d'extension : indicateurs et tableau de bord
 * `secondary` du socle Reporting, types d'export des rapports.
 */
return [
    ReportCardIssuance::class => get(PdoReportCardIssuance::class),
    FeeCompliance::class => get(PdoFeeCompliance::class),
    NationalIdCoverage::class => get(PdoNationalIdCoverage::class),
    ExamOutcomes::class => get(PdoExamOutcomes::class),
    RepeaterRoster::class => get(PdoRepeaterRoster::class),
    NetworkDashboard::class => autowire()->constructorParameter('minimumCellSize', get('reporting.disclosure.minimum_cell_size')),
    'reporting.indicators.secondary' => [SecondaryIndicators::class],
    'reporting.dashboards.secondary' => [new ConfiguredDashboard('secondary', 'Enseignement secondaire', SecondaryIndicators::EXPECTED, 'secondary.dashboard.read')],
    'integrations.export_types.secondary_dashboards' => DashboardExportTypes::types(),
];
