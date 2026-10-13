<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/** Doublure d'EnrollmentDirectory. */
final class InMemoryEnrollments implements EnrollmentDirectory
{
    /** @var array<string, EnrollmentInfo> */
    private array $items = [];

    public function add(string $tenantId, string $id, string $personId, string $establishmentId, ?string $structureId = 'c1', string $academicYearId = 'ay1', string $status = EnrollmentInfo::ACTIVE): void
    {
        $this->items[$id] = new EnrollmentInfo($id, $tenantId, $personId, $establishmentId, $structureId, $academicYearId, $status);
    }

    public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo
    {
        $item = $this->items[$enrollmentId] ?? null;

        return $item !== null && $item->tenantId === $tenantId ? $item : null;
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return array_values(array_filter($this->items, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->personId === $personId));
    }

    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        return array_values(array_map(static fn (EnrollmentInfo $e): string => $e->personId, array_filter($this->items, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->structureId === $structureId)));
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return array_values(array_map(static fn (EnrollmentInfo $e): string => $e->personId, array_filter($this->items, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->establishmentId === $establishmentId)));
    }
}
