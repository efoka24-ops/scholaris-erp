<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/**
 * Personnel du secondaire (T911, EX-RHP-01, EX-RHP-02) : statut d'emploi (fonctionnaire, contractuel de l'État, salarié du privé,
 * vacataire) des membres actifs du socle Staff affectés à l'établissement, et heures du mois : déclarées par l'appel, validées ou
 * rejetées au socle (TeacherWorkload), signées au cahier de textes (SignedLessons). Aucune identité n'est dupliquée.
 */
final class StaffService
{
    public const PROFILES = 'se_staff_profiles';
    public const STATUSES = ['civil_servant', 'state_contract', 'private_employee', 'hourly'];
    public const CIVIL_SERVANT = 'civil_servant';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly StaffDirectory $staff,
        private readonly TeacherWorkload $workload,
        private readonly PersonDirectory $people,
        private readonly SignedLessons $lessons,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $body person_id, employment_status, grade_label?, payroll_ref?
     * @return array<string, mixed>
     */
    public function upsert(Principal $principal, string $establishmentId, array $body): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, StaffAccess::PROFILE_MANAGE);
        $this->access->assertWhole($principal, $perimeter, self::PROFILES);
        $input = new Input($body);
        $personId = (string) $input->reference('person_id');
        $status = (string) $input->enum('employment_status', self::STATUSES);
        $grade = $input->string('grade_label', 80, false, true);
        $payrollRef = $input->string('payroll_ref', 40, false, true);
        $input->done();
        $member = $this->activeMember($principal->tenantId, $establishmentId, $personId);
        $staffId = (string) $member->staffId;
        $now = $this->clock->now();
        $existing = $this->store->select(self::PROFILES, $principal->tenantId, $establishmentId, ['staff_id' => $staffId], 'id', 1)[0] ?? null;
        $values = ['person_id' => $member->personId, 'employment_status' => $status, 'grade_label' => $grade, 'payroll_ref' => $payrollRef, 'updated_at' => $now];
        if ($existing === null) {
            $id = (string) new Ulid();
            $this->store->insert(self::PROFILES, $principal->tenantId, $establishmentId, $values + ['id' => $id, 'staff_id' => $staffId, 'created_at' => $now]);
        } else {
            $id = (string) $existing['id'];
            $this->store->update(self::PROFILES, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        }
        $this->access->audit($principal, 'secondary.staff.profile_saved', self::PROFILES, $id, $existing === null ? null : self::resource($existing), $values);

        return $this->profile($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Membres actifs de l'établissement, avec leur profil (statut null tant qu'il n'est pas renseigné), par nom.
     *
     * @return list<array<string, mixed>>
     */
    public function profiles(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PROFILE_READ);

        return $this->roster($principal->tenantId, $establishmentId);
    }

    /**
     * Heures du mois par membre : déclarées par l'appel, validées et rejetées au socle ; minutes signées au cahier de textes.
     *
     * @return list<array<string, mixed>>
     */
    public function hours(Principal $principal, string $establishmentId, string $month): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::HOURS_READ);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw HttpException::validation(['month' => ['Mois au format AAAA-MM attendu.']]);
        }
        $logged = $this->lessons->minutesByTeacher($principal->tenantId, $establishmentId, $month);
        $hours = [];
        foreach ($this->roster($principal->tenantId, $establishmentId) as $profile) {
            $personId = (string) $profile['person_id'];
            $summary = $this->workload->hoursOf($principal->tenantId, $establishmentId, $personId, $month);
            $hours[] = [
                'staff_profile_id' => $profile['id'], 'staff_id' => $profile['staff_id'], 'person_id' => $personId,
                'full_name' => $profile['full_name'], 'employment_status' => $profile['employment_status'],
                'declared_minutes' => $summary['declared_minutes'], 'validated_minutes' => $summary['validated_minutes'],
                'rejected_minutes' => $summary['rejected_minutes'], 'logged_minutes' => $logged[$personId] ?? 0,
            ];
        }

        return $hours;
    }

    /** @return array<string, mixed> */
    public function profile(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->store->findOrFail(self::PROFILES, $tenantId, $establishmentId, $id, 'Profil de personnel introuvable.');
        $person = $this->people->find($tenantId, (string) $row['person_id']);

        return self::resource($row) + ['full_name' => $person?->fullName() ?? ''];
    }

    /**
     * Profil d'un membre encore actif dans l'établissement.
     *
     * @return array<string, mixed>
     */
    public function activeProfile(string $tenantId, string $establishmentId, string $profileId): array
    {
        $row = $this->store->findOrFail(self::PROFILES, $tenantId, $establishmentId, $profileId, 'Profil de personnel introuvable.');
        $this->activeMember($tenantId, $establishmentId, (string) $row['person_id']);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'staff_id' => (string) $row['staff_id'], 'person_id' => (string) $row['person_id'],
            'employment_status' => (string) $row['employment_status'], 'grade_label' => $row['grade_label'] === null ? null : (string) $row['grade_label'],
            'payroll_ref' => $row['payroll_ref'] === null ? null : (string) $row['payroll_ref'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function roster(string $tenantId, string $establishmentId): array
    {
        $byStaff = array_column($this->store->select(self::PROFILES, $tenantId, $establishmentId, [], 'id', 5000), null, 'staff_id');
        $personIds = $this->staff->teacherIdsIn($tenantId, $establishmentId);
        $names = [];
        foreach ($this->people->findMany($tenantId, $personIds) as $person) {
            $names[$person->id] = $person->fullName();
        }
        $items = [];
        foreach ($personIds as $personId) {
            $member = $this->staff->find($tenantId, $personId);
            if ($member === null || $member->staffId === null || $member->status !== 'active') {
                continue;
            }
            $row = $byStaff[$member->staffId] ?? null;
            $items[] = ($row === null ? [
                'id' => null, 'staff_id' => $member->staffId, 'person_id' => $personId, 'employment_status' => null,
                'grade_label' => $member->grade, 'payroll_ref' => null, 'updated_at' => null,
            ] : self::resource($row)) + ['full_name' => $names[$personId] ?? ''];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['full_name'], (string) $b['full_name']));

        return $items;
    }

    /** Membre actif du socle Staff affecté à cet établissement (sinon 404, y compris pour un autre client ou établissement). */
    private function activeMember(string $tenantId, string $establishmentId, string $personId): StaffInfo
    {
        $member = in_array($personId, $this->staff->teacherIdsIn($tenantId, $establishmentId), true) ? $this->staff->find($tenantId, $personId) : null;
        if ($member === null || $member->staffId === null || $member->status !== 'active') {
            throw HttpException::notFound('Personnel actif introuvable dans cet établissement.');
        }

        return $member;
    }
}
