<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/** Lecture des classes publiques et des listes nominatives via les contrats du socle. */
final class PdoClassRoster implements ClassRoster
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly OrgUnitTree $tree,
        private readonly EnrollmentDirectory $enrollments,
    ) {
    }

    public function classesOf(string $tenantId, string $establishmentId, string $yearId): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT structure_id FROM pub_class_profiles WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? ORDER BY structure_id',
        );
        $query->execute([$tenantId, $establishmentId, $yearId]);
        $classes = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) ?: [] as $structureId) {
            $class = $this->find($tenantId, $establishmentId, (string) $structureId);
            if ($class !== null) {
                $classes[] = $class;
            }
        }
        usort($classes, static fn (ClassInfo $left, ClassInfo $right): int => strcmp($left->label, $right->label));

        return $classes;
    }

    public function find(string $tenantId, string $establishmentId, string $structureId): ?ClassInfo
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT level_codes, shift, homeroom_staff_id FROM pub_class_profiles WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ?',
        );
        $query->execute([$tenantId, $establishmentId, $structureId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $structure = $this->tree->structure($tenantId, $establishmentId, $structureId);
        if (!is_array($row) || $structure === null) {
            return null;
        }

        return new ClassInfo(
            $tenantId,
            $establishmentId,
            $structureId,
            $structure->name,
            array_values(array_filter(explode(',', (string) $row['level_codes']), static fn (string $code): bool => $code !== '')),
            (string) $row['shift'],
            $row['homeroom_staff_id'] !== null ? (string) $row['homeroom_staff_id'] : null,
        );
    }

    public function pupilIdsIn(string $tenantId, string $establishmentId, string $structureId, string $date): array
    {
        if ($this->find($tenantId, $establishmentId, $structureId) === null) {
            return [];
        }

        return $this->enrollments->personIdsInStructure($tenantId, $structureId);
    }

    public function headcount(string $tenantId, string $establishmentId, string $structureId, string $date): int
    {
        return count($this->pupilIdsIn($tenantId, $establishmentId, $structureId, $date));
    }
}
