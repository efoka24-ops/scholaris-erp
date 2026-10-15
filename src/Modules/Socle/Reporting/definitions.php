<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Socle\Identity\Contracts\TileIndicators;
use Scholaris\Modules\Socle\Identity\Contracts\TodoProvider as IdentityTodoProvider;
use Scholaris\Modules\Socle\Reporting\Application\ApprovalTodoProvider;
use Scholaris\Modules\Socle\Reporting\Application\TodoAggregator;
use Scholaris\Modules\Socle\Reporting\Contracts\PendingApprovalLister;
use Scholaris\Modules\Socle\Reporting\Infrastructure\AuditPendingApprovals;
use Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators\AssessmentIndicators;
use Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators\AttendanceIndicators;
use Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators\BillingIndicators;
use Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators\PeopleIndicators;
use Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators\SchedulingIndicators;
use Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators\StaffIndicators;
use Scholaris\Modules\Socle\Reporting\Infrastructure\ReportingTileIndicators;

/**
 * Liaisons de Reporting. Les entrées `reporting.indicators.*` et `reporting.todo.*` sont les points d'enregistrement
 * des fournisseurs (un module ou une application ajoute la sienne sous un autre suffixe).
 */
return [
    IdentityTodoProvider::class => get(TodoAggregator::class),
    TileIndicators::class => autowire(ReportingTileIndicators::class),
    PendingApprovalLister::class => autowire(AuditPendingApprovals::class),

    'reporting.indicators.reporting' => [
        PeopleIndicators::class,
        BillingIndicators::class,
        AttendanceIndicators::class,
        SchedulingIndicators::class,
        StaffIndicators::class,
        AssessmentIndicators::class,
    ],
    'reporting.todo.reporting' => [ApprovalTodoProvider::class],
    // Tableaux de bord déclarés par les applications (S21) : clé de liste `reporting.dashboards.<application>`.
    'reporting.dashboards.socle' => [],
    'privacy.tenant_data_source.reporting' => \DI\autowire(\Scholaris\Modules\Socle\Reporting\Infrastructure\PrivacyExportSource::class),
];
