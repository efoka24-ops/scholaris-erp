<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;

/** Les brouillons n'existent pas pour les autres modules ; les inscriptions « sous réserve » comptent comme inscrites. */
final class PdoEnrollmentDirectory implements EnrollmentDirectory, StructureEnrollments
{
    public function __construct(private readonly PdoStore $store)
    {
    }

    public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo
    {
        $rows = $this->store->rows(
            "SELECT * FROM enrollments WHERE tenant_id = ? AND id = ? AND status <> 'draft'",
            [$tenantId, $enrollmentId],
        );

        return $rows === [] ? null : $this->info($rows[0]);
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        $rows = $this->store->rows(
            "SELECT * FROM enrollments WHERE tenant_id = ? AND person_id = ? AND status <> 'draft' ORDER BY id",
            [$tenantId, $personId],
        );

        return array_map($this->info(...), $rows);
    }

    public function inStructure(string $tenantId, string $structureId): array
    {
        $rows = $this->store->rows(
            "SELECT * FROM enrollments WHERE tenant_id = ? AND structure_id = ? AND status <> 'draft' ORDER BY id",
            [$tenantId, $structureId],
        );

        return array_map($this->info(...), $rows);
    }

    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        return $this->personIds('structure_id', $tenantId, $structureId);
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return $this->personIds('establishment_id', $tenantId, $establishmentId);
    }

    /** @return list<string> */
    private function personIds(string $column, string $tenantId, string $value): array
    {
        $rows = $this->store->rows(
            sprintf("SELECT DISTINCT person_id FROM enrollments WHERE tenant_id = ? AND %s = ? AND status IN ('active', 'conditional') ORDER BY person_id", $column),
            [$tenantId, $value],
        );

        return array_map(static fn (array $r): string => (string) $r['person_id'], $rows);
    }

    /** @param array<string, mixed> $row */
    private function info(array $row): EnrollmentInfo
    {
        return new EnrollmentInfo(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['person_id'],
            (string) $row['establishment_id'],
            $row['structure_id'] === null ? null : (string) $row['structure_id'],
            (string) $row['academic_year_id'],
            (string) $row['status'],
            $row['internal_number'] === null ? null : (string) $row['internal_number'],
        );
    }
}
