<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\StructureLookup;

/** Recherche par code dans hi_schools, hi_departments et hi_programs (insensible à la casse). */
final class PdoStructureLookup implements StructureLookup
{
    public function __construct(
        private readonly Store $store,
        private readonly AcademicDirectory $directory,
    ) {
    }

    public function programsByCode(string $tenantId, string $establishmentId, string $code, ?string $schoolCode = null): array
    {
        $wanted = strtoupper(trim($code));
        $schoolIds = $this->schoolIds($tenantId, $establishmentId, $schoolCode);
        $found = [];
        foreach ($this->store->select('hi_programs', $tenantId, $establishmentId, [], 'code', 5000) as $row) {
            if (strtoupper((string) $row['code']) !== $wanted || ($schoolIds !== null && !in_array((string) $row['school_id'], $schoolIds, true))) {
                continue;
            }
            $program = $this->directory->program($tenantId, $establishmentId, (string) $row['id']);
            if ($program instanceof ProgramInfo) {
                $found[] = $program;
            }
        }

        return $found;
    }

    public function departmentsByCode(string $tenantId, string $establishmentId, string $code, ?string $schoolCode = null): array
    {
        $wanted = strtoupper(trim($code));
        $schoolIds = $this->schoolIds($tenantId, $establishmentId, $schoolCode);
        $found = [];
        foreach ($this->store->select('hi_departments', $tenantId, $establishmentId, [], 'code', 5000) as $row) {
            if (strtoupper((string) $row['code']) !== $wanted || ($schoolIds !== null && !in_array((string) $row['school_id'], $schoolIds, true))) {
                continue;
            }
            $found[] = ['id' => (string) $row['id'], 'school_id' => (string) $row['school_id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']];
        }

        return $found;
    }

    /** @return list<string>|null null = toutes les écoles */
    private function schoolIds(string $tenantId, string $establishmentId, ?string $schoolCode): ?array
    {
        if ($schoolCode === null || trim($schoolCode) === '') {
            return null;
        }
        $wanted = strtoupper(trim($schoolCode));
        $ids = [];
        foreach ($this->store->select('hi_schools', $tenantId, $establishmentId, [], 'code', 500) as $row) {
            if (strtoupper((string) $row['code']) === $wanted) {
                $ids[] = (string) $row['id'];
            }
        }

        return $ids;
    }
}
