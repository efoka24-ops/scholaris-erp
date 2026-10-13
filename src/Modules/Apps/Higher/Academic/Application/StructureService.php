<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;

/**
 * Écoles, départements, filières (et spécialités), niveaux et groupes (EX-ACA-01/02). Chaque objet est d'abord créé dans le socle
 * par StructureWriter (D1 : unité `school`/`department`, structure `track`/`level`/`group`), puis étendu 1-1 dans hi_* avec le
 * même identifiant. Le régime d'une filière ne change jamais (RG-ORG-7) : changer de régime = créer une nouvelle filière.
 */
final class StructureService
{
    public function __construct(
        private readonly Store $store,
        private readonly StructureWriter $writer,
        private readonly Access $access,
        private readonly AcademicClock $clock,
    ) {
    }

    /**
     * Clés de périmètre d'une ligne (ScopedBySchool).
     *
     * @param array<string, mixed> $row
     * @return array<string, ?string>
     */
    public static function scopeOf(string $kind, array $row): array
    {
        $id = (string) $row['id'];

        return match ($kind) {
            'school' => ['school' => $id],
            'department' => ['school' => (string) $row['school_id'], 'department' => $id],
            'program' => ['school' => (string) $row['school_id'], 'department' => (string) $row['department_id'], 'program' => $id],
            'level' => ['school' => (string) $row['school_id'], 'department' => (string) $row['department_id'], 'program' => (string) $row['program_id'], 'level' => $id],
            'group' => [
                'school' => (string) $row['school_id'], 'department' => (string) $row['department_id'], 'program' => (string) $row['program_id'],
                'level' => (string) $row['level_id'], 'group' => $id,
            ],
            default => throw new \InvalidArgumentException('Nature inconnue : ' . $kind),
        };
    }

    /**
     * @param array<string, mixed> $v code, name, director_person_id
     * @return array<string, mixed>
     */
    public function createSchool(Principal $principal, string $establishmentId, array $v): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $this->access->assertWholeEstablishment($principal, $perimeter, 'higher_school');
        $tenantId = $principal->tenantId;
        $this->assertFree('hi_schools', $tenantId, $establishmentId, ['code' => $v['code']], 'Ce code d\'école est déjà utilisé.');
        $id = $this->writer->createOrgUnit($tenantId, $establishmentId, 'school', (string) $v['name'], null, $principal->userId);
        $now = $this->clock->now();
        $this->store->insert('hi_schools', $tenantId, $establishmentId, [
            'id' => $id, 'code' => $v['code'], 'name' => $v['name'], 'director_person_id' => $v['director_person_id'] ?? null,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail('hi_schools', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.school.created', 'higher_school', $id, null, $row);

        return $row;
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function updateSchool(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $row = $this->store->findOrFail('hi_schools', $tenantId, $establishmentId, $id, 'École introuvable.');
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('school', $row), 'higher_school', $id);
        if (isset($v['code']) && $v['code'] !== $row['code']) {
            $this->assertFree('hi_schools', $tenantId, $establishmentId, ['code' => $v['code']], 'Ce code d\'école est déjà utilisé.');
        }
        if (isset($v['name']) && $v['name'] !== $row['name']) {
            $this->writer->updateOrgUnit($tenantId, $establishmentId, $id, ['name' => (string) $v['name']], $principal->userId);
        }

        return $this->save('hi_schools', $principal, $establishmentId, $row, $v, 'higher.school.updated', 'higher_school');
    }

    /**
     * @param array<string, mixed> $v school_id, code, name, head_person_id
     * @return array<string, mixed>
     */
    public function createDepartment(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $school = $this->store->find('hi_schools', $tenantId, $establishmentId, (string) $v['school_id'])
            ?? throw HttpException::validation(['school_id' => ['École introuvable dans cet établissement.']]);
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('school', $school), 'higher_department', (string) $school['id']);
        $this->assertFree('hi_departments', $tenantId, $establishmentId, ['school_id' => $school['id'], 'code' => $v['code']], 'Ce code de département est déjà utilisé dans l\'école.');
        $id = $this->writer->createOrgUnit($tenantId, $establishmentId, 'department', (string) $v['name'], (string) $school['id'], $principal->userId);
        $now = $this->clock->now();
        $this->store->insert('hi_departments', $tenantId, $establishmentId, [
            'id' => $id, 'school_id' => $school['id'], 'code' => $v['code'], 'name' => $v['name'], 'head_person_id' => $v['head_person_id'] ?? null,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail('hi_departments', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.department.created', 'higher_department', $id, null, $row);

        return $row;
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function updateDepartment(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $row = $this->store->findOrFail('hi_departments', $tenantId, $establishmentId, $id, 'Département introuvable.');
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('department', $row), 'higher_department', $id);
        if (isset($v['code']) && $v['code'] !== $row['code']) {
            $this->assertFree('hi_departments', $tenantId, $establishmentId, ['school_id' => $row['school_id'], 'code' => $v['code']], 'Ce code de département est déjà utilisé dans l\'école.');
        }
        if (isset($v['name']) && $v['name'] !== $row['name']) {
            $this->writer->updateOrgUnit($tenantId, $establishmentId, $id, ['name' => (string) $v['name']], $principal->userId);
        }

        return $this->save('hi_departments', $principal, $establishmentId, $row, $v, 'higher.department.updated', 'higher_department');
    }

    /**
     * @param array<string, mixed> $v department_id, code, name, regime, teaching_language, study_mode, duration_years, parent_program_id
     * @return array<string, mixed>
     */
    public function createProgram(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $department = $this->store->find('hi_departments', $tenantId, $establishmentId, (string) $v['department_id'])
            ?? throw HttpException::validation(['department_id' => ['Département introuvable dans cet établissement.']]);
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('department', $department), 'higher_program', (string) $department['id']);
        $parent = null;
        if (($v['parent_program_id'] ?? null) !== null) {
            $parent = $this->store->find('hi_programs', $tenantId, $establishmentId, (string) $v['parent_program_id']);
            if ($parent === null || $parent['school_id'] !== $department['school_id'] || $parent['regime'] !== $v['regime']) {
                throw HttpException::validation(['parent_program_id' => ['Une spécialité dépend d\'une filière de la même école et du même régime.']]);
            }
        }
        $this->assertFree('hi_programs', $tenantId, $establishmentId, ['school_id' => $department['school_id'], 'code' => $v['code']], 'Ce code de filière est déjà utilisé dans l\'école.');
        $id = $this->writer->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::TRACK, (string) $v['name'], (string) $department['id']), $principal->userId);
        $now = $this->clock->now();
        $this->store->insert('hi_programs', $tenantId, $establishmentId, [
            'id' => $id, 'school_id' => $department['school_id'], 'department_id' => $department['id'], 'parent_program_id' => $parent['id'] ?? null,
            'code' => $v['code'], 'name' => $v['name'], 'regime' => $v['regime'], 'teaching_language' => $v['teaching_language'] ?? 'fr',
            'study_mode' => $v['study_mode'] ?? 'day', 'duration_years' => $v['duration_years'], 'status' => 'active',
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail('hi_programs', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.program.created', 'higher_program', $id, null, $row);

        return $row;
    }

    /**
     * RG-ORG-7 : le régime ne change jamais (409). Les autres caractéristiques restent modifiables.
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function updateProgram(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $row = $this->store->findOrFail('hi_programs', $tenantId, $establishmentId, $id, 'Filière introuvable.');
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('program', $row), 'higher_program', $id);
        if (array_key_exists('regime', $v) && $v['regime'] !== $row['regime']) {
            throw new HttpException(
                409,
                'Régime non modifiable',
                'Une filière n\'a qu\'un régime (RG-ORG-7) : pour changer de régime, créez une nouvelle filière.',
                'https://api.scholaris.cm/errors/higher-regime-immutable',
            );
        }
        if (isset($v['code']) && $v['code'] !== $row['code']) {
            $this->assertFree('hi_programs', $tenantId, $establishmentId, ['school_id' => $row['school_id'], 'code' => $v['code']], 'Ce code de filière est déjà utilisé dans l\'école.');
        }
        if (isset($v['duration_years'])) {
            $highest = $this->store->select('hi_levels', $tenantId, $establishmentId, ['program_id' => $id], 'ordinal DESC', 1)[0]['ordinal'] ?? 0;
            if ((int) $highest > (int) $v['duration_years']) {
                throw HttpException::validation(['duration_years' => ['La durée ne peut pas être inférieure au dernier niveau déjà créé (' . (int) $highest . ').']]);
            }
        }
        if (isset($v['name']) && $v['name'] !== $row['name']) {
            $this->writer->updateStructure($tenantId, $establishmentId, $id, ['name' => (string) $v['name']], $principal->userId);
        }
        unset($v['regime']);

        return $this->save('hi_programs', $principal, $establishmentId, $row, $v, 'higher.program.updated', 'higher_program');
    }

    /**
     * @param array<string, mixed> $v program_id, ordinal, code, name
     * @return array<string, mixed>
     */
    public function createLevel(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $program = $this->store->find('hi_programs', $tenantId, $establishmentId, (string) $v['program_id'])
            ?? throw HttpException::validation(['program_id' => ['Filière introuvable dans cet établissement.']]);
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('program', $program), 'higher_level', (string) $program['id']);
        if ((int) $v['ordinal'] > (int) $program['duration_years']) {
            throw HttpException::validation(['ordinal' => ['Le rang du niveau dépasse la durée de la filière (' . (int) $program['duration_years'] . ' ans).']]);
        }
        $this->assertFree('hi_levels', $tenantId, $establishmentId, ['program_id' => $program['id'], 'ordinal' => $v['ordinal']], 'Ce niveau existe déjà dans la filière.');
        $id = $this->writer->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::LEVEL, (string) $v['name'], (string) $program['department_id'], (string) $program['id']), $principal->userId);
        $now = $this->clock->now();
        $this->store->insert('hi_levels', $tenantId, $establishmentId, [
            'id' => $id, 'school_id' => $program['school_id'], 'department_id' => $program['department_id'], 'program_id' => $program['id'],
            'ordinal' => $v['ordinal'], 'code' => $v['code'], 'name' => $v['name'],
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail('hi_levels', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.level.created', 'higher_level', $id, null, $row);

        return $row;
    }

    /**
     * @param array<string, mixed> $v code, name
     * @return array<string, mixed>
     */
    public function updateLevel(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $row = $this->store->findOrFail('hi_levels', $tenantId, $establishmentId, $id, 'Niveau introuvable.');
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('level', $row), 'higher_level', $id);
        if (isset($v['name']) && $v['name'] !== $row['name']) {
            $this->writer->updateStructure($tenantId, $establishmentId, $id, ['name' => (string) $v['name']], $principal->userId);
        }

        return $this->save('hi_levels', $principal, $establishmentId, $row, $v, 'higher.level.updated', 'higher_level');
    }

    /**
     * @param array<string, mixed> $v level_id, code, name, capacity
     * @return array<string, mixed>
     */
    public function createGroup(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $level = $this->store->find('hi_levels', $tenantId, $establishmentId, (string) $v['level_id'])
            ?? throw HttpException::validation(['level_id' => ['Niveau introuvable dans cet établissement.']]);
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('level', $level), 'higher_group', (string) $level['id']);
        $this->assertFree('hi_groups', $tenantId, $establishmentId, ['level_id' => $level['id'], 'code' => $v['code']], 'Ce code de groupe existe déjà pour ce niveau.');
        $capacity = isset($v['capacity']) ? (int) $v['capacity'] : null;
        $id = $this->writer->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::GROUP, (string) $v['name'], (string) $level['department_id'], (string) $level['id'], $capacity), $principal->userId);
        $now = $this->clock->now();
        $this->store->insert('hi_groups', $tenantId, $establishmentId, [
            'id' => $id, 'school_id' => $level['school_id'], 'department_id' => $level['department_id'], 'program_id' => $level['program_id'],
            'level_id' => $level['id'], 'code' => $v['code'], 'name' => $v['name'], 'capacity' => $capacity,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail('hi_groups', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.group.created', 'higher_group', $id, null, $row);

        return $row;
    }

    /**
     * @param array<string, mixed> $v code, name, capacity
     * @return array<string, mixed>
     */
    public function updateGroup(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $row = $this->store->findOrFail('hi_groups', $tenantId, $establishmentId, $id, 'Groupe introuvable.');
        $this->access->assertWritable($principal, $perimeter, self::scopeOf('group', $row), 'higher_group', $id);
        if (isset($v['code']) && $v['code'] !== $row['code']) {
            $this->assertFree('hi_groups', $tenantId, $establishmentId, ['level_id' => $row['level_id'], 'code' => $v['code']], 'Ce code de groupe existe déjà pour ce niveau.');
        }
        $changes = array_intersect_key($v, ['name' => true, 'capacity' => true]);
        if ($changes !== []) {
            $this->writer->updateStructure($tenantId, $establishmentId, $id, $changes, $principal->userId);
        }

        return $this->save('hi_groups', $principal, $establishmentId, $row, $v, 'higher.group.updated', 'higher_group');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    private function save(string $table, Principal $principal, string $establishmentId, array $row, array $v, string $action, string $type): array
    {
        $id = (string) $row['id'];
        /** @var array<string, scalar|null> $changes */
        $changes = array_filter($v, static fn (mixed $value): bool => is_scalar($value) || $value === null);
        if ($changes === []) {
            return $row;
        }
        $this->store->update($table, $principal->tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $id]);
        $after = $this->store->findOrFail($table, $principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, $action, $type, $id, $row, $after);

        return $after;
    }

    /** @param array<string, scalar|null> $where */
    private function assertFree(string $table, string $tenantId, string $establishmentId, array $where, string $message): void
    {
        if ($this->store->count($table, $tenantId, $establishmentId, $where) > 0) {
            throw HttpException::conflict($message);
        }
    }
}
