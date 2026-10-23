<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Dashboards\Application\PrimaryIndicators;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\PromotionMapClosureCheck;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\SheetsClosureCheck;
use Scholaris\Modules\Socle\Reporting\Contracts\ConfiguredDashboard;

/**
 * Points d'extension de Reporting et d'Organisation : indicateurs et tableau de bord `primary` (GET /dashboards/primary, permission
 * `primary.dashboard.read`), contrôles préalables à la clôture d'année. Une clé de liste par application.
 */
return [
    'reporting.indicators.primary' => [PrimaryIndicators::class],
    'reporting.dashboards.primary' => [new ConfiguredDashboard('primary', 'École primaire', PrimaryIndicators::EXPECTED, 'primary.dashboard.read')],
    'organisation.closure.checks.primary' => [SheetsClosureCheck::class, PromotionMapClosureCheck::class],
];
