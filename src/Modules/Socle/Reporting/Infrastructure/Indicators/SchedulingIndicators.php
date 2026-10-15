<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators;

use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;

/** Créneaux effectivement tenus ce jour, d'après TimetableDirectory. */
final class SchedulingIndicators implements IndicatorProvider
{
    public function __construct(private readonly TimetableDirectory $timetable)
    {
    }

    public function module(): string
    {
        return 'scheduling';
    }

    public function indicators(IndicatorScope $scope): array
    {
        return [Indicator::of('scheduling.slots_today', 'Créneaux tenus aujourd\'hui', count($this->timetable->slotsOn($scope->tenantId, $scope->establishmentId, $scope->today)))];
    }
}
