<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;

/** Fournisseur fictif d'indicateurs d'application (supérieur, primaire, secondaire, maternelle) pour le filtrage du résumé. */
final class AppIndicators implements IndicatorProvider
{
    public function module(): string
    {
        return 'people';
    }

    public function indicators(IndicatorScope $scope): array
    {
        return [
            Indicator::of('higher.success_rate', 'Taux de réussite', 87, 'percent'),
            Indicator::unavailable('higher.defenses', 'Soutenances planifiées', 'Calcul impossible.'),
            Indicator::unavailable('primary.absent_morning', 'Absents ce matin', 'Aucun niveau primaire dans cet établissement.'),
            Indicator::unavailable('secondary.o1', 'Taux de couverture des programmes', 'Aucune année scolaire en cours.'),
            Indicator::unavailable('nursery.attendance', 'Présence en maternelle', 'Aucune section maternelle.'),
        ];
    }
}
