<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Application;

use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Indicateur territorial « effectifs par niveau et sexe » (T654 ; CDC, Indicateurs : effectifs, indice de parité filles ÷ garçons,
 * dénominateur des élèves par enseignant et par salle). Cases `level=<niveau>;sex=F|M` ; aucun nom.
 */
final class EnrolmentAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly SchoolPopulation $population, private readonly PersonDirectory $people)
    {
    }

    public function indicator(): string
    {
        return 'public_schools.enrolment';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $counts = [];
        foreach ($this->population->at($tenantId, $establishmentId, $referenceDate) as $class) {
            $genders = [];
            foreach ($this->people->findMany($tenantId, $class['pupils']) as $person) {
                $genders[$person->id] = $person->gender;
            }
            foreach ($class['pupils'] as $id) {
                $cell = 'level=' . $class['level'] . ';sex=' . (in_array($genders[$id] ?? null, ['female', 'F', 'f'], true) ? 'F' : 'M');
                $counts[$cell] = ($counts[$cell] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }
}
