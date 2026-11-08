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
use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMemberInfo;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/**
 * Personnel du secondaire (T911, EX-RHP-01, EX-RHP-02) : TOUT le personnel actif de l'établissement (enseignant, administratif,
 * service, entretien), lu par le contrat EstablishmentStaffRoster du socle, avec sa fonction ; l'établissement fixe le statut d'emploi
 * (fonctionnaire, contractuel de l'État, salarié du privé, vacataire) et la catégorie. Heures du mois des enseignants : déclarées par
 * l'appel, validées ou rejetées au socle (TeacherWorkload), signées au cahier de textes (SignedLessons). Aucune identité dupliquée.
 */
final class StaffService
{
    public const PROFILES = 'se_staff_profiles';
    public const STATUSES = ['civil_servant', 'state_contract', 'private_employee', 'hourly'];
    public const CIVIL_SERVANT = 'civil_servant';
    public const CATEGORIES = ['teaching', 'administrative', 'service', 'maintenance'];
    public const TEACHING = 'teaching';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly EstablishmentStaffRoster $roster,
        private readonly TeacherWorkload $workload,
        private readonly PersonDirectory $people,
        private readonly SignedLessons $lessons,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $body person_id, employment_status, staff_category?, grade_label?, payroll_ref?
     * @return array<string, mixed>
     */
    public function upsert(Principal $principal, string $establishmentId, array $body): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, StaffAccess::PROFILE_MANAGE);
        $this->access->assertWhole($principal, $perimeter, self::PROFILES);
        $input = new Input($body);
        $personId = (string) $input->reference('person_id');
        $status = (string) $input->enum('employment_status', self::STATUSES);
        $category = $input->enum('staff_category', self::CATEGORIES, false);
        $grade = $input->string('grade_label', 80, false, true);
        $payrollRef = $input->string('payroll_ref', 40, false, true);
        $input->done();
        $member = $this->activeMember($principal->tenantId, $establishmentId, $personId);
        $now = $this->clock->now();
        $existing = $this->store->select(self::PROFILES, $principal->tenantId, $establishmentId, ['staff_id' => $member->staffId], 'id', 1)[0] ?? null;
        $values = [
            'person_id' => $member->personId, 'employment_status' => $status, 'staff_category' => $category ?? ($existing['staff_category'] ?? null) ?? self::defaultCategory($member),
            'grade_label' => $grade, 'payroll_ref' => $payrollRef, 'updated_at' => $now,
        ];
        if ($existing === null) {
            $id = (string) new Ulid();
            $this->store->insert(self::PROFILES, $principal->tenantId, $establishmentId, $values + ['id' => $id, 'staff_id' => $member->staffId, 'created_at' => $now]);
        } else {
            $id = (string) $existing['id'];
            $this->store->update(self::PROFILES, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        }
        $this->access->audit($principal, 'secondary.staff.profile_saved', self::PROFILES, $id, $existing === null ? null : self::resource($existing), $values);

        return $this->roster($principal->tenantId, $establishmentId, $member->personId)[0] ?? [];
    }

    /**
     * Tout le personnel actif de l'établissement avec son profil (statut null tant qu'il n'est pas renseigné), par nom ; filtre
     * facultatif par catégorie.
     *
     * @return list<array<string, mixed>>
     */
    public function profiles(Principal $principal, string $establishmentId, ?string $category = null): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PROFILE_READ);
        if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
            throw HttpException::validation(['category' => ['Catégorie attendue : ' . implode(', ', self::CATEGORIES) . '.']]);
        }
        $items = $this->roster($principal->tenantId, $establishmentId);

        return $category === null ? $items : array_values(array_filter($items, static fn (array $item): bool => $item['staff_category'] === $category));
    }

    /**
     * Heures du mois des enseignants : déclarées par l'appel, validées et rejetées au socle ; minutes signées au cahier de textes.
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
            if ($profile['teaches'] !== true) {
                continue;
            }
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

    /**
     * Profil d'un membre encore actif dans l'établissement, avec `teaches` (enseignant : heures issues de l'appel).
     *
     * @return array<string, mixed>
     */
    public function activeProfile(string $tenantId, string $establishmentId, string $profileId): array
    {
        $row = $this->store->findOrFail(self::PROFILES, $tenantId, $establishmentId, $profileId, 'Profil de personnel introuvable.');
        $member = $this->activeMember($tenantId, $establishmentId, (string) $row['person_id']);

        return $row + ['teaches' => $member->teaches];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'staff_id' => (string) $row['staff_id'], 'person_id' => (string) $row['person_id'],
            'employment_status' => (string) $row['employment_status'],
            'staff_category' => ($row['staff_category'] ?? null) === null ? null : (string) $row['staff_category'],
            'grade_label' => $row['grade_label'] === null ? null : (string) $row['grade_label'],
            'payroll_ref' => $row['payroll_ref'] === null ? null : (string) $row['payroll_ref'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function roster(string $tenantId, string $establishmentId, ?string $onlyPerson = null): array
    {
        $byStaff = array_column($this->store->select(self::PROFILES, $tenantId, $establishmentId, [], 'id', 5000), null, 'staff_id');
        $members = array_values(array_filter($this->roster->membersOf($tenantId, $establishmentId), static fn (StaffMemberInfo $m): bool => $onlyPerson === null || $m->personId === $onlyPerson));
        $names = [];
        foreach ($this->people->findMany($tenantId, array_map(static fn (StaffMemberInfo $m): string => $m->personId, $members)) as $person) {
            $names[$person->id] = $person->fullName();
        }
        $items = [];
        foreach ($members as $member) {
            $row = $byStaff[$member->staffId] ?? null;
            $base = $row === null ? [
                'id' => null, 'staff_id' => $member->staffId, 'person_id' => $member->personId, 'employment_status' => null,
                'staff_category' => self::defaultCategory($member), 'grade_label' => $member->grade, 'payroll_ref' => null, 'updated_at' => null,
            ] : self::resource($row);
            $base['staff_category'] ??= self::defaultCategory($member);
            $items[] = $base + [
                'full_name' => $names[$member->personId] ?? '', 'function' => $member->grade, 'socle_kind' => $member->kind,
                'socle_category' => $member->category, 'teaches' => $member->teaches,
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['full_name'], (string) $b['full_name']));

        return $items;
    }

    /** Membre actif de l'établissement (enseignant ou non), sinon 404, y compris pour un autre client ou établissement. */
    private function activeMember(string $tenantId, string $establishmentId, string $personId): StaffMemberInfo
    {
        foreach ($this->roster->membersOf($tenantId, $establishmentId) as $member) {
            if ($member->personId === $personId && $member->status === 'active') {
                return $member;
            }
        }

        throw HttpException::notFound('Personnel actif introuvable dans cet établissement.');
    }

    /** Catégorie de départ : enseignant si le socle lui connaît une affectation d'enseignement, sinon à renseigner. */
    private static function defaultCategory(StaffMemberInfo $member): ?string
    {
        return $member->teaches ? self::TEACHING : null;
    }
}
