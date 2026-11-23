<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Discipline;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/** Inscriptions de la doublure DisciplineSchool (contrat People\EnrollmentDirectory). Données fictives. */
final class DisciplineEnrollments implements EnrollmentDirectory
{
    public function __construct(private readonly DisciplineSchool $school)
    {
    }

    public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo
    {
        foreach ($this->school->enrollments as $enrollment) {
            if ($enrollment->tenantId === $tenantId && $enrollment->id === $enrollmentId) {
                return $enrollment;
            }
        }

        return null;
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return $this->school->forPerson($tenantId, $personId);
    }

    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        return $this->school->personIdsInStructure($tenantId, $structureId);
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return $this->school->personIdsInEstablishment($tenantId, $establishmentId);
    }
}
