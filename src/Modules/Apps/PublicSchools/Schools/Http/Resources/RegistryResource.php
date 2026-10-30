<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Http\Resources;

/** Liste blanche API, quel que soit le contenu PDO fourni. */
final class RegistryResource
{
    private const PROFILE_FIELDS = ['id', 'tenant_id', 'establishment_id', 'name', 'school_code', 'territory_id', 'created_at', 'updated_at'];
    private const SNAPSHOT_FIELDS = [
        'id', 'tenant_id', 'establishment_id', 'territory_snapshot', 'school_year', 'version', 'classrooms_count', 'classroom_condition_fr', 'classroom_condition_en',
        'water_access', 'latrines_count', 'electricity', 'connectivity', 'equipment_fr', 'equipment_en', 'status', 'correction_reason', 'closed_by', 'closed_at', 'created_at', 'updated_at',
    ];

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function profile(array $row): array
    {
        return array_intersect_key($row, array_flip(self::PROFILE_FIELDS));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip(self::SNAPSHOT_FIELDS));
    }
}
