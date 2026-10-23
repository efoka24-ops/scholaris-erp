<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;

/** Effectifs, d'après EnrollmentDirectory. */
final class PeopleIndicators implements IndicatorProvider
{
    public function __construct(private readonly EnrollmentDirectory $enrollments)
    {
    }

    public function module(): string
    {
        return 'people';
    }

    public function indicators(IndicatorScope $scope): array
    {
        $ids = array_unique($this->enrollments->personIdsInEstablishment($scope->tenantId, $scope->establishmentId));

        return [Indicator::of('people.enrolled', 'Inscrits', count($ids))];
    }
}
