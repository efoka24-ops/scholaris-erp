<?php

declare(strict_types=1);

namespace Tests\Support\Secondary\CarteScolaire;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/** Inscriptions de la doublure FakeSchool (contrat People\EnrollmentDirectory). Données fictives. */
final class FakeEnrollments implements EnrollmentDirectory
{
    public function __construct(private readonly FakeSchool $school)
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
        return array_values(array_filter($this->school->enrollments, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->personId === $personId));
    }

    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        return $this->ids(static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->structureId === $structureId);
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return $this->ids(static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->establishmentId === $establishmentId);
    }

    /**
     * @param \Closure(EnrollmentInfo): bool $keep
     * @return list<string>
     */
    private function ids(\Closure $keep): array
    {
        return array_values(array_map(static fn (EnrollmentInfo $e): string => $e->personId, array_filter($this->school->enrollments, $keep)));
    }
}
