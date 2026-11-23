<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Infrastructure;

use Scholaris\Modules\Apps\Primary\Academic\Application\ReferentialService;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyInfo;
use Scholaris\Modules\Apps\Primary\Support\Store;

final class PdoCompetencyDirectory implements CompetencyDirectory
{
    public function __construct(private readonly Store $store)
    {
    }

    public function forLevel(string $tenantId, string $establishmentId, string $academicYearId, string $levelStructureId): array
    {
        $domains = [];
        foreach ($this->store->select(ReferentialService::TABLE_DOMAINS, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'sort_order, code', 500) as $domain) {
            $domains[(string) $domain['id']] = (int) $domain['sort_order'];
        }
        $rows = $this->store->select(ReferentialService::TABLE_COMPETENCIES, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId, 'level_structure_id' => $levelStructureId, 'active' => 1], 'sort_order, code', 1000);
        usort($rows, static fn (array $a, array $b): int => [$domains[(string) $a['domain_id']] ?? 0, (int) $a['sort_order'], (string) $a['code']] <=> [$domains[(string) $b['domain_id']] ?? 0, (int) $b['sort_order'], (string) $b['code']]);

        return array_map(self::map(...), $rows);
    }

    public function domainsOf(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $domains = [];
        foreach ($this->store->select(ReferentialService::TABLE_DOMAINS, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'sort_order, code', 500) as $domain) {
            $domains[(string) $domain['id']] = [
                'code' => (string) $domain['code'], 'label_fr' => (string) $domain['label_fr'],
                'label_en' => $domain['label_en'] === null ? null : (string) $domain['label_en'], 'sort_order' => (int) $domain['sort_order'],
            ];
        }

        return $domains;
    }

    public function find(string $tenantId, string $establishmentId, string $competencyId): ?CompetencyInfo
    {
        $row = $this->store->find(ReferentialService::TABLE_COMPETENCIES, $tenantId, $establishmentId, $competencyId);

        return $row === null ? null : self::map($row);
    }

    public function findMany(string $tenantId, string $establishmentId, array $ids): array
    {
        $found = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $info = $this->find($tenantId, $establishmentId, $id);
            if ($info !== null) {
                $found[$id] = $info;
            }
        }

        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function map(array $row): CompetencyInfo
    {
        return new CompetencyInfo(
            (string) $row['id'],
            (string) $row['academic_year_id'],
            (string) $row['level_structure_id'],
            (string) $row['domain_id'],
            $row['subject_id'] === null ? null : (string) $row['subject_id'],
            (string) $row['code'],
            (string) $row['label_fr'],
            $row['label_en'] === null ? null : (string) $row['label_en'],
            (int) $row['sort_order'],
            (int) $row['active'] === 1,
        );
    }
}
