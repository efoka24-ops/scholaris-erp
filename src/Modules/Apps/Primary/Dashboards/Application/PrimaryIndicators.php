<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;

/**
 * Indicateurs du primaire pour le tableau de bord `primary` du socle (GET /dashboards/primary) : mêmes chiffres que le tableau de bord
 * du directeur, sur tout l'établissement. Établissement sans niveau primaire : indicateurs indisponibles, jamais un zéro inventé.
 */
final class PrimaryIndicators implements IndicatorProvider
{
    public const EXPECTED = [
        'primary.learners' => 'Élèves inscrits',
        'primary.absent_morning' => 'Absents ce matin',
        'primary.absent_afternoon' => 'Absents cet après-midi',
        'primary.late_payers' => 'Élèves en retard de paiement',
        'primary.sheets_late' => 'Classes en retard de saisie des compétences',
    ];

    public function __construct(private readonly DirectorDashboard $dashboard)
    {
    }

    public function module(): string
    {
        return 'people';
    }

    public function indicators(IndicatorScope $scope): array
    {
        $data = $this->dashboard->compute($scope->tenantId, $scope->establishmentId, null);
        /** @var array<string, int> $totals */
        $totals = $data['totals'];
        if ($totals['classes'] === 0) {
            return array_map(static fn (string $key, string $label): Indicator => Indicator::unavailable($key, $label, 'Aucun niveau primaire dans cet établissement.'), array_keys(self::EXPECTED), self::EXPECTED);
        }

        return [
            Indicator::of('primary.learners', self::EXPECTED['primary.learners'], $totals['learners'], 'count', ['classes' => $totals['classes']]),
            Indicator::of('primary.absent_morning', self::EXPECTED['primary.absent_morning'], $totals['absent_morning']),
            Indicator::of('primary.absent_afternoon', self::EXPECTED['primary.absent_afternoon'], $totals['absent_afternoon']),
            Indicator::of('primary.late_payers', self::EXPECTED['primary.late_payers'], $totals['late_payers'], 'count', ['balance_xaf' => $totals['late_balance_xaf']]),
            Indicator::of('primary.sheets_late', self::EXPECTED['primary.sheets_late'], $totals['sheets_late']),
        ];
    }
}
