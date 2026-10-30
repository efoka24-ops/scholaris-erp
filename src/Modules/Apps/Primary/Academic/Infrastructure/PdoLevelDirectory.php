<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Infrastructure;

use Scholaris\Modules\Apps\Primary\Academic\Application\ReferentialService;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelProfile;
use Scholaris\Modules\Apps\Primary\Support\Store;

final class PdoLevelDirectory implements LevelDirectory
{
    public function __construct(private readonly Store $store)
    {
    }

    public function profile(string $tenantId, string $establishmentId, string $levelStructureId): ?LevelProfile
    {
        $row = $this->store->select(ReferentialService::TABLE_PROFILES, $tenantId, $establishmentId, ['level_structure_id' => $levelStructureId], 'id', 1)[0] ?? null;

        return $row === null ? null : self::map($row);
    }

    public function all(string $tenantId, string $establishmentId): array
    {
        return array_map(self::map(...), $this->store->select(ReferentialService::TABLE_PROFILES, $tenantId, $establishmentId, [], 'sort_order, id', 500));
    }

    /** @param array<string, mixed> $row */
    private static function map(array $row): LevelProfile
    {
        return new LevelProfile(
            (string) $row['id'],
            (string) $row['level_structure_id'],
            $row['section_org_unit_id'] === null ? null : (string) $row['section_org_unit_id'],
            $row['cycle_code'] === null ? null : (string) $row['cycle_code'],
            (int) $row['sort_order'],
            (int) $row['is_final_level'] === 1,
            (int) $row['is_exam_level'] === 1,
            (string) $row['language'],
        );
    }
}
