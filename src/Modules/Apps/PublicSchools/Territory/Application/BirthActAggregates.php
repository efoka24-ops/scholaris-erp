<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Application;

use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/** Indicateur « élèves sans acte de naissance vérifié » (CDC, Indicateurs ; RG-ELV-2, préparation du CEP/FSLC) : `act=verified|missing`. */
final class BirthActAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly SchoolPopulation $population, private readonly PupilRegistry $pupils)
    {
    }

    public function indicator(): string
    {
        return 'public_schools.birth_act';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $counts = ['act=missing' => 0, 'act=verified' => 0];
        foreach ($this->population->at($tenantId, $establishmentId, $referenceDate) as $class) {
            foreach ($class['pupils'] as $id) {
                ++$counts[$this->pupils->find($tenantId, $id)?->birthActVerified === true ? 'act=verified' : 'act=missing'];
            }
        }

        return array_filter($counts);
    }
}
