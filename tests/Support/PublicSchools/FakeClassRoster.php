<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Tests\Support\Attendance\InMemoryEnrollments;

/** Doublure de ClassRoster : classes déclarées, élèves lus dans les inscriptions fictives. */
final class FakeClassRoster implements ClassRoster
{
    /** @var array<string, ClassInfo> */
    private array $classes = [];

    public function __construct(private readonly InMemoryEnrollments $enrollments)
    {
    }

    /** @param list<string> $levelCodes */
    public function add(string $tenantId, string $establishmentId, string $structureId, string $label, array $levelCodes, ?string $homeroomStaffId): void
    {
        $this->classes[$tenantId . '|' . $establishmentId . '|' . $structureId] = new ClassInfo($tenantId, $establishmentId, $structureId, $label, $levelCodes, 'full_day', $homeroomStaffId);
    }

    public function classesOf(string $tenantId, string $establishmentId, string $yearId): array
    {
        return array_values(array_filter($this->classes, static fn (ClassInfo $class): bool => $class->tenantId === $tenantId && $class->establishmentId === $establishmentId));
    }

    public function find(string $tenantId, string $establishmentId, string $structureId): ?ClassInfo
    {
        return $this->classes[$tenantId . '|' . $establishmentId . '|' . $structureId] ?? null;
    }

    public function pupilIdsIn(string $tenantId, string $establishmentId, string $structureId, string $date): array
    {
        if ($this->find($tenantId, $establishmentId, $structureId) === null) {
            return [];
        }
        $ids = $this->enrollments->personIdsInStructure($tenantId, $structureId);
        sort($ids);

        return $ids;
    }

    public function headcount(string $tenantId, string $establishmentId, string $structureId, string $date): int
    {
        return count($this->pupilIdsIn($tenantId, $establishmentId, $structureId, $date));
    }
}
