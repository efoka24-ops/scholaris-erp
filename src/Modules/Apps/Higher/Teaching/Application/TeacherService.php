<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/**
 * Dossiers enseignants (EX-ENS-01) et salles. Le dossier IPES complète la fiche du personnel du socle (Staff) : statut permanent,
 * vacataire ou invité, grade, diplômes, établissement d'origine et, pour un fonctionnaire vacataire, autorisation de l'employeur.
 */
final class TeacherService
{
    public const TABLE = 'hi_teacher_profiles';
    public const ROOMS = 'hi_rooms';
    public const STATUSES = [StaffInfo::PERMANENT, StaffInfo::VACATAIRE, StaffInfo::INVITE];

    public function __construct(
        private readonly TeachingStore $store,
        private readonly TeachingAccess $access,
        private readonly StaffDirectory $staff,
        private readonly OrgUnitTree $tree,
        private readonly TeachingClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data person_id, department_id, teacher_status, grade, diplomas, origin_establishment, public_servant,
     *     employer_authorization_ref, employer_authorization_document, employer_authorization_until
     * @return array<string, mixed>
     */
    public function save(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $personId = (string) $data['person_id'];
        $staff = $this->staff->find($tenantId, $personId);
        if ($staff === null || $staff->staffId === null) {
            throw HttpException::validation(['person_id' => ['Fiche du personnel introuvable : créez d\'abord l\'enseignant au module Personnel.']]);
        }
        $current = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;
        $departmentId = isset($data['department_id']) ? (string) $data['department_id'] : (string) ($current['department_id'] ?? '');
        $schoolId = $departmentId === '' ? null : $this->schoolOf($tenantId, $establishmentId, $departmentId);
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::TEACHER_MANAGE);
        $this->access->assertWritable($principal, $perimeter, ['school_id' => $schoolId, 'department_id' => $departmentId === '' ? null : $departmentId], 'hi_teacher_profile', $personId);

        $status = (string) ($data['teacher_status'] ?? $current['teacher_status'] ?? $staff->kind);
        $values = [
            'staff_id' => $staff->staffId,
            'school_id' => $schoolId,
            'department_id' => $departmentId === '' ? null : $departmentId,
            'teacher_status' => $status,
            'grade' => $data['grade'] ?? $current['grade'] ?? $staff->grade,
            'diplomas' => array_key_exists('diplomas', $data) ? json_encode(array_values((array) $data['diplomas']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : ($current['diplomas'] ?? '[]'),
            'origin_establishment' => $data['origin_establishment'] ?? $current['origin_establishment'] ?? null,
            'public_servant' => array_key_exists('public_servant', $data) ? ((bool) $data['public_servant'] ? 1 : 0) : (int) ($current['public_servant'] ?? 0),
            'employer_authorization_ref' => $data['employer_authorization_ref'] ?? $current['employer_authorization_ref'] ?? null,
            'employer_authorization_document' => $data['employer_authorization_document'] ?? $current['employer_authorization_document'] ?? null,
            'employer_authorization_until' => $data['employer_authorization_until'] ?? $current['employer_authorization_until'] ?? null,
            'updated_at' => $this->clock->now(),
        ];
        if ($status === StaffInfo::VACATAIRE && $values['public_servant'] === 1 && ($values['employer_authorization_ref'] === null || $values['employer_authorization_document'] === null)) {
            throw HttpException::validation(['employer_authorization_ref' => ['Un vacataire fonctionnaire doit produire l\'autorisation de son employeur (référence et document).']]);
        }
        if ($current === null) {
            $id = (string) new Ulid();
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, ['id' => $id, 'person_id' => $personId, 'created_by' => $principal->userId, 'created_at' => $values['updated_at']] + $values);
        } else {
            $id = (string) $current['id'];
            $this->store->update(self::TABLE, $tenantId, $establishmentId, $values, ['id' => $id]);
        }
        $this->access->audit($tenantId, $principal->userId, 'higher.teacher.saved', 'hi_teacher_profile', $id, $current, $values);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed>|null */
    public function profile(string $tenantId, string $establishmentId, string $personId): ?array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $data school_id, code, name, capacity
     * @return array<string, mixed>
     */
    public function saveRoom(Principal $principal, string $establishmentId, ?string $id, array $data): array
    {
        $tenantId = $principal->tenantId;
        $current = $id === null ? null : $this->store->findOrFail(self::ROOMS, $tenantId, $establishmentId, $id, 'Salle introuvable.');
        $schoolId = (string) ($data['school_id'] ?? $current['school_id'] ?? '');
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::TIMETABLE_MANAGE);
        $this->access->assertWritable($principal, $perimeter, ['school_id' => $schoolId], 'hi_room', $id ?? $schoolId);
        $now = $this->clock->now();
        $values = array_filter([
            'school_id' => $schoolId, 'code' => $data['code'] ?? null, 'name' => $data['name'] ?? null, 'capacity' => $data['capacity'] ?? null,
            'active' => array_key_exists('active', $data) ? ((bool) $data['active'] ? 1 : 0) : null,
        ], static fn (mixed $v): bool => $v !== null) + ['updated_at' => $now];
        if ($current === null) {
            $id = (string) new Ulid();
            $this->store->insert(self::ROOMS, $tenantId, $establishmentId, ['id' => $id, 'active' => 1, 'created_at' => $now] + $values);
        } else {
            $this->store->update(self::ROOMS, $tenantId, $establishmentId, $values, ['id' => (string) $id]);
        }
        $this->access->audit($tenantId, $principal->userId, 'higher.room.saved', 'hi_room', (string) $id, $current, $values);

        return $this->store->findOrFail(self::ROOMS, $tenantId, $establishmentId, (string) $id);
    }

    private function schoolOf(string $tenantId, string $establishmentId, string $orgUnitId): string
    {
        $ancestors = $this->tree->ancestorsOf($tenantId, $establishmentId, $orgUnitId);
        if ($ancestors === [] && $this->tree->descendantsOf($tenantId, $establishmentId, $orgUnitId) === []) {
            throw HttpException::validation(['department_id' => ['Département introuvable.']]);
        }

        return $ancestors === [] ? $orgUnitId : $ancestors[count($ancestors) - 1];
    }
}
