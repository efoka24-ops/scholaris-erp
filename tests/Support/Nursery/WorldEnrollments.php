<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;

/** Doublure en mémoire des inscriptions (annuaire par client, établissement et section). */
final class WorldEnrollments implements EnrollmentDirectory, StructureEnrollments
{
    /** @var array<string, EnrollmentInfo> */
    private array $items = [];

    public function add(string $tenantId, string $id, string $personId, string $establishmentId, ?string $structureId, string $academicYearId = 'AY-2026', string $status = EnrollmentInfo::ACTIVE): string
    {
        $this->items[$id] = new EnrollmentInfo($id, $tenantId, $personId, $establishmentId, $structureId, $academicYearId, $status);

        return $id;
    }

    public function close(string $tenantId, string $id, string $status): void
    {
        $item = $this->items[$id] ?? null;
        if ($item !== null && $item->tenantId === $tenantId) {
            $this->items[$id] = new EnrollmentInfo($item->id, $item->tenantId, $item->personId, $item->establishmentId, $item->structureId, $item->academicYearId, $status);
        }
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
        return array_values(array_map(static fn (EnrollmentInfo $e): string => $e->personId, array_filter($this->items, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->structureId === $structureId && in_array($e->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true))));
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return array_values(array_map(static fn (EnrollmentInfo $e): string => $e->personId, array_filter($this->items, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->establishmentId === $establishmentId && in_array($e->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true))));
    }

    public function inStructure(string $tenantId, string $structureId): array
    {
        return array_values(array_filter($this->items, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->structureId === $structureId));
    }
}
