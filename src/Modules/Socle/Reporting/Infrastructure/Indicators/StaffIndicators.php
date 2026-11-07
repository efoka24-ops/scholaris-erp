<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators;

use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/** Effectif enseignant, d'après StaffDirectory (les heures de vacation ne sont exposées par aucun contrat). */
final class StaffIndicators implements IndicatorProvider
{
    public function __construct(private readonly StaffDirectory $staff)
    {
    }

    public function module(): string
    {
        return 'staff';
    }

    public function indicators(IndicatorScope $scope): array
    {
        return [Indicator::of('staff.teachers_active', 'Enseignants actifs', count(array_unique($this->staff->teacherIdsIn($scope->tenantId, $scope->establishmentId))))];
    }
}
