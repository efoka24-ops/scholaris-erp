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
    \Scholaris\Modules\Socle\Reporting\Contracts\ApprovalWorkflow::class => autowire(\Scholaris\Modules\Socle\Reporting\Infrastructure\PdoApprovalWorkflow::class),

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
    // Agrégats par territoire (PP4, T613) : fournisseurs `reporting.territory_aggregates.<application>`, seuil de masquage paramétré.
    'reporting.disclosure.minimum_cell_size' => 5,
    \Scholaris\Modules\Socle\Reporting\Contracts\DisclosurePolicy::class => autowire(\Scholaris\Modules\Socle\Reporting\Application\ThresholdDisclosurePolicy::class),
    \Scholaris\Modules\Socle\Reporting\Application\TerritoryAggregateReader::class => autowire()->constructorParameter('minimumCellSize', get('reporting.disclosure.minimum_cell_size')),
    // Lecture des agrégats territoriaux par les applications (DOC-28 des écoles publiques, lot P3) : même lecteur masqué.
    \Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregates::class => get(\Scholaris\Modules\Socle\Reporting\Application\TerritoryAggregateReader::class),
    'reporting.territory_aggregates.socle' => [],
    'console.command.' . \Scholaris\Modules\Socle\Reporting\Console\AggregateTerritoriesCommand::NAME => autowire(\Scholaris\Modules\Socle\Reporting\Console\AggregateTerritoriesCommand::class),
    'privacy.tenant_data_source.reporting' => \DI\autowire(\Scholaris\Modules\Socle\Reporting\Infrastructure\PrivacyExportSource::class),
];
