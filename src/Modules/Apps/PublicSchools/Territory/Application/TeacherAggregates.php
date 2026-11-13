<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Application;

use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/** Indicateur « enseignants en poste » (dénominateur des élèves par enseignant, CDC, Indicateurs) : une case `staff=teachers`. */
final class TeacherAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly StaffDirectory $staff)
    {
    }

    public function indicator(): string
    {
        return 'public_schools.teachers';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $count = count($this->staff->teacherIdsIn($tenantId, $establishmentId));

        return $count === 0 ? [] : ['staff=teachers' => $count];
    }
}
