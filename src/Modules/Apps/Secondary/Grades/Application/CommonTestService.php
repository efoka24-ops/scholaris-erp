<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Épreuves communes par niveau (EX-NOT-04, T870) organisées par le censeur ou l'animateur pédagogique : une épreuve commune réunit
 * les épreuves du socle des classes du niveau (identifiants opaques). Les notes restent celles du socle.
 */
final class CommonTestService
{
    public const TABLE = 'se_common_tests';
    public const MANAGE = ['secondary.grades.validate', 'secondary.grades.lock'];
    public const STATUSES = ['planned', 'held', 'closed'];

    public function __construct(private readonly Store $store, private readonly Access $access, private readonly SecondaryClock $clock)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, self::TABLE);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, [
            'id' => $id, 'academic_year_id' => $data['academic_year_id'], 'level_code' => $data['level_code'], 'subject_id' => $data['subject_id'],
            'period_id' => $data['period_id'], 'title' => $data['title'], 'held_on' => $data['held_on'] ?? null, 'organised_by' => $principal->userId,
            'assessment_ids' => json_encode(array_values($data['assessment_ids'] ?? []), JSON_THROW_ON_ERROR), 'status' => 'planned',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.grades.common_test.created', self::TABLE, $id, null, ['level_code' => $data['level_code']]);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $data title?, held_on?, assessment_ids?, status?
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, self::TABLE);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Épreuve commune introuvable.');
        if ($row['status'] === 'closed') {
            throw HttpException::conflict('Cette épreuve commune est close.');
        }
        $values = ['updated_at' => $this->clock->now()];
        foreach (['title', 'held_on', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = $data[$field];
            }
        }
        if (array_key_exists('assessment_ids', $data)) {
            $values['assessment_ids'] = json_encode(array_values((array) $data['assessment_ids']), JSON_THROW_ON_ERROR);
        }
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        $this->access->audit($principal, 'secondary.grades.common_test.updated', self::TABLE, $id, ['status' => $row['status']], $values);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        $ids = json_decode((string) ($row['assessment_ids'] ?? '[]'), true);

        return [
            'id' => $row['id'], 'academic_year_id' => $row['academic_year_id'], 'level_code' => $row['level_code'], 'subject_id' => $row['subject_id'],
            'period_id' => $row['period_id'], 'title' => $row['title'], 'held_on' => $row['held_on'], 'organised_by' => $row['organised_by'],
            'assessment_ids' => is_array($ids) ? array_values($ids) : [], 'status' => $row['status'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }
}
