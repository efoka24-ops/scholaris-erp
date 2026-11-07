<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Jury de délibération (EX-DEL-03) : un jury par groupe, période (semestre) et session ; président (directeur d'école, R04) et
 * membres, quorum, ordre du jour, présents ; points de jury plafonnés (RG-DEL-2), motivés, listés nominativement au PV.
 * La séance elle-même (décisions, clôture, signatures, publication, réouverture) est celle du socle (`/deliberations`), gardée
 * par HigherDeliberationGuard qui tient ce jury à jour.
 *
 * États : prepared → simulated → in_session → closed → signed → published ; reopened (EX-DEL-08) puis closed…
 */
final class JuryService
{
    public const TABLE = 'hi_juries';
    public const EDITABLE = ['prepared', 'simulated', 'in_session', 'reopened'];
    public const SESSIONS = ['normal', 'resit'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly AcademicDirectory $academic,
        private readonly PersonDirectory $people,
        private readonly SettingsReader $settings,
        private readonly RulesProvider $rules,
        private readonly AcademicClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, ?string>
     */
    public static function scope(array $row): array
    {
        return [
            'school' => (string) $row['school_id'], 'program' => (string) $row['program_id'],
            'level' => (string) $row['level_id'], 'group' => (string) $row['structure_id'],
        ];
    }

    /**
     * Jury lisible (permissions au choix, périmètre), sinon 404.
     *
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function readable(Principal $principal, string $establishmentId, string $id, array $permissions = [DeliberationPermissions::READ]): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Jury introuvable.');
        $this->access->assertReadable($perimeter, self::scope($row));

        return $row;
    }

    /**
     * Jury modifiable par la personne (permission + périmètre : 404 hors lecture, 403 journalisé hors écriture).
     *
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function writable(Principal $principal, string $establishmentId, string $id, array $permissions): array
    {
        $row = $this->readable($principal, $establishmentId, $id, [DeliberationPermissions::READ, ...$permissions]);
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $this->access->assertWritable($principal, $perimeter, self::scope($row), 'jury', $id);

        return $row;
    }

    /**
     * @param array<string, mixed> $data structure_id, period_id, period_label, academic_year_id, exam_session, president_person_id, members, agenda, quorum_min
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $group = $this->academic->group($tenantId, $establishmentId, (string) $data['structure_id'])
            ?? throw HttpException::validation(['structure_id' => ['Groupe introuvable dans cet établissement.']]);
        $program = $this->academic->program($tenantId, $establishmentId, $group->programId)
            ?? throw HttpException::validation(['structure_id' => ['Filière du groupe introuvable.']]);
        $scope = ['school' => $program->schoolId, 'program' => $program->id, 'level' => $group->levelId, 'group' => $group->id];
        $perimeter = $this->access->require($principal, $establishmentId, [DeliberationPermissions::PREPARE]);
        $this->access->assertWritable($principal, $perimeter, $scope, 'jury', $group->id);

        $session = (string) ($data['exam_session'] ?? 'normal');
        if ($this->store->count(self::TABLE, $tenantId, $establishmentId, ['structure_id' => $group->id, 'period_id' => (string) $data['period_id'], 'exam_session' => $session]) > 0) {
            throw HttpException::conflict('Un jury existe déjà pour ce groupe, cette période et cette session.');
        }
        $members = $this->members((string) $data['president_person_id'], (array) ($data['members'] ?? []));
        $quorum = isset($data['quorum_min']) ? (int) $data['quorum_min'] : $this->defaultQuorum($tenantId, $establishmentId, count($members));
        if ($quorum < 1 || $quorum > count($members)) {
            throw HttpException::validation(['quorum_min' => [sprintf('Le quorum doit être compris entre 1 et %d membres.', count($members))]]);
        }

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $program, $group, $data, $session, $quorum, $members, $principal, $now): void {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                'id' => $id, 'school_id' => $program->schoolId, 'program_id' => $program->id, 'level_id' => $group->levelId, 'structure_id' => $group->id,
                'academic_year_id' => (string) $data['academic_year_id'], 'period_id' => (string) $data['period_id'],
                'period_label' => (string) ($data['period_label'] ?? $data['period_id']), 'exam_session' => $session, 'regime' => $program->regime,
                'president_person_id' => (string) $data['president_person_id'], 'quorum_min' => $quorum, 'agenda' => $data['agenda'] ?? null,
                'status' => 'prepared', 'deliberation_ref' => null, 'minutes_fingerprint' => null, 'minutes_version' => 0, 'signed_at' => null,
                'published_at' => null, 'reopen_count' => 0, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->replaceMembers($tenantId, $establishmentId, $id, $members);
        });
        $this->access->audit($principal, 'higher.deliberation.jury_created', 'jury', $id, null, ['structure_id' => $group->id, 'period_id' => $data['period_id'], 'exam_session' => $session]);

        return $this->present($tenantId, $establishmentId, $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /**
     * Composition, quorum, ordre du jour : modifiables tant que la séance n'est pas close.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $data): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, [DeliberationPermissions::PREPARE]);
        $this->assertEditable($row);
        $values = ['updated_at' => $this->clock->now()];
        if (array_key_exists('agenda', $data)) {
            $values['agenda'] = $data['agenda'] === null ? null : (string) $data['agenda'];
        }
        $president = isset($data['president_person_id']) ? (string) $data['president_person_id'] : (string) $row['president_person_id'];
        $members = null;
        if (isset($data['members']) || isset($data['president_person_id'])) {
            $current = array_column($this->membersOf($tenantId, $establishmentId, $id), 'person_id');
            $members = $this->members($president, isset($data['members']) ? (array) $data['members'] : $current);
            $values['president_person_id'] = $president;
        }
        $count = $members === null ? count($this->membersOf($tenantId, $establishmentId, $id)) : count($members);
        $quorum = isset($data['quorum_min']) ? (int) $data['quorum_min'] : min((int) $row['quorum_min'], $count);
        if ($quorum < 1 || $quorum > $count) {
            throw HttpException::validation(['quorum_min' => [sprintf('Le quorum doit être compris entre 1 et %d membres.', $count)]]);
        }
        $values['quorum_min'] = $quorum;
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $values, $members): void {
            $this->store->update(self::TABLE, $tenantId, $establishmentId, $values, ['id' => $id]);
            if ($members !== null) {
                $this->replaceMembers($tenantId, $establishmentId, $id, $members);
            }
        });
        $this->access->audit($principal, 'higher.deliberation.jury_updated', 'jury', $id, null, $values);

        return $this->present($tenantId, $establishmentId, $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /**
     * Présents à la séance : le quorum atteint fait passer le jury « en séance ».
     *
     * @param list<string> $present
     * @return array<string, mixed>
     */
    public function attendance(Principal $principal, string $establishmentId, string $id, array $present): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, [DeliberationPermissions::PREPARE]);
        $this->assertEditable($row);
        $members = array_column($this->membersOf($tenantId, $establishmentId, $id), 'person_id');
        $unknown = array_values(array_diff($present, $members));
        if ($unknown !== []) {
            throw HttpException::validation(['present' => ['Personne(s) hors de la composition du jury : ' . implode(', ', $unknown) . '.']]);
        }
        $reached = count(array_unique($present)) >= (int) $row['quorum_min'];
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $members, $present, $reached, $row): void {
            foreach ($members as $person) {
                $this->store->update('hi_jury_members', $tenantId, $establishmentId, ['present' => in_array($person, $present, true) ? 1 : 0], ['jury_id' => $id, 'person_id' => $person]);
            }
            $status = $reached && in_array($row['status'], ['prepared', 'simulated'], true) ? 'in_session' : (string) $row['status'];
            $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => $status, 'updated_at' => $this->clock->now()], ['id' => $id]);
        });
        $this->access->audit($principal, 'higher.deliberation.attendance', 'jury', $id, null, ['present' => $present, 'quorum_reached' => $reached]);

        return $this->present($tenantId, $establishmentId, $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /**
     * Points de jury (RG-DEL-2) : sur la moyenne de semestre, plafonnés (règlement `jury_points_max`, défaut 0,5), motivés ; 0 retire.
     *
     * @return array<string, mixed>
     */
    public function grantPoints(Principal $principal, string $establishmentId, string $id, string $personId, float $points, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, [DeliberationPermissions::PRESIDE]);
        $this->assertEditable($row);
        if (trim($reason) === '' && $points > 0) {
            throw HttpException::validation(['reason' => ['Les points de jury sont motivés : indiquez le motif, il figurera au procès-verbal.']]);
        }
        $cap = (float) ($this->rules->parameters($row)['jury_points_max'] ?? 0.5);
        $granted = round(max(0.0, min($points, $cap)), 2);
        $where = ['jury_id' => $id, 'person_id' => $personId];
        $this->store->delete('hi_jury_points', $tenantId, $establishmentId, $where);
        if ($granted > 0) {
            $this->store->insert('hi_jury_points', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'jury_id' => $id, 'person_id' => $personId, 'points_requested' => $points, 'points_granted' => $granted,
                'reason' => trim($reason), 'granted_by' => $principal->userId, 'created_at' => $this->clock->now(),
            ]);
        }
        $this->access->audit($principal, 'higher.deliberation.jury_points', 'jury', $id, null, ['person_id' => $personId, 'requested' => $points, 'granted' => $granted]);

        return ['person_id' => $personId, 'points_requested' => $points, 'points_granted' => $granted, 'cap' => $cap, 'capped' => $granted < $points];
    }

    /** @return array<string, float> personne => points accordés */
    public function pointsOf(string $tenantId, string $establishmentId, string $juryId): array
    {
        $out = [];
        foreach ($this->store->select('hi_jury_points', $tenantId, $establishmentId, ['jury_id' => $juryId], 'person_id') as $point) {
            $out[(string) $point['person_id']] = (float) $point['points_granted'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function membersOf(string $tenantId, string $establishmentId, string $juryId): array
    {
        return $this->store->select('hi_jury_members', $tenantId, $establishmentId, ['jury_id' => $juryId], 'member_role DESC, person_id');
    }

    /**
     * Vue complète : membres (noms), quorum, points de jury, signatures du PV en vigueur, historique.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(string $tenantId, string $establishmentId, array $row): array
    {
        $id = (string) $row['id'];
        $members = $this->membersOf($tenantId, $establishmentId, $id);
        $points = $this->store->select('hi_jury_points', $tenantId, $establishmentId, ['jury_id' => $id], 'person_id');
        $signatures = $this->store->select('hi_minutes_signatures', $tenantId, $establishmentId, ['jury_id' => $id, 'minutes_version' => (int) $row['minutes_version']], 'signed_at');
        $names = $this->names($tenantId, [...array_column($members, 'person_id'), ...array_column($points, 'person_id')]);
        $present = count(array_filter($members, static fn (array $m): bool => (int) $m['present'] === 1));

        return self::jury($row) + [
            'members' => array_map(static fn (array $m): array => [
                'person_id' => (string) $m['person_id'], 'name' => $names[(string) $m['person_id']] ?? null, 'member_role' => (string) $m['member_role'],
                'present' => (int) $m['present'] === 1,
                'signed' => in_array((string) $m['person_id'], array_column($signatures, 'person_id'), true),
            ], $members),
            'present_count' => $present,
            'quorum_reached' => $present >= (int) $row['quorum_min'],
            'jury_points' => array_map(static fn (array $p): array => [
                'person_id' => (string) $p['person_id'], 'name' => $names[(string) $p['person_id']] ?? null, 'points_requested' => (float) $p['points_requested'],
                'points_granted' => (float) $p['points_granted'], 'reason' => (string) $p['reason'], 'created_at' => (string) $p['created_at'],
            ], $points),
            'signatures' => array_map(static fn (array $s): array => [
                'person_id' => (string) $s['person_id'], 'member_role' => (string) $s['member_role'], 'fingerprint' => $s['fingerprint'], 'signed_at' => (string) $s['signed_at'],
            ], $signatures),
            'events' => array_map(static fn (array $e): array => [
                'event_type' => (string) $e['event_type'], 'reason' => $e['reason'], 'actor_id' => (string) $e['actor_id'], 'created_at' => (string) $e['created_at'],
            ], $this->store->select('hi_jury_events', $tenantId, $establishmentId, ['jury_id' => $id], 'created_at')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function jury(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'school_id' => (string) $row['school_id'], 'program_id' => (string) $row['program_id'], 'level_id' => (string) $row['level_id'],
            'structure_id' => (string) $row['structure_id'], 'academic_year_id' => (string) $row['academic_year_id'], 'period_id' => (string) $row['period_id'],
            'period_label' => (string) $row['period_label'], 'exam_session' => (string) $row['exam_session'], 'regime' => (string) $row['regime'],
            'president_person_id' => (string) $row['president_person_id'], 'quorum_min' => (int) $row['quorum_min'], 'agenda' => $row['agenda'],
            'status' => (string) $row['status'], 'deliberation_ref' => $row['deliberation_ref'], 'minutes_fingerprint' => $row['minutes_fingerprint'],
            'minutes_version' => (int) $row['minutes_version'], 'signed_at' => $row['signed_at'], 'published_at' => $row['published_at'],
            'reopen_count' => (int) $row['reopen_count'], 'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param list<string> $personIds
     * @return array<string, string>
     */
    public function names(string $tenantId, array $personIds): array
    {
        $out = [];
        foreach ($this->people->findMany($tenantId, array_values(array_unique($personIds))) as $person) {
            $out[$person->id] = $person->fullName();
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function assertEditable(array $row): void
    {
        if (!in_array($row['status'], self::EDITABLE, true)) {
            throw HttpException::conflict('La délibération est close : jury, présents et points de jury ne sont plus modifiables (RG-DEL-4). Une réouverture motivée par le directeur académique est nécessaire.');
        }
    }

    /**
     * @param array<mixed> $others
     * @return array<string, string> personne => rôle (le président d'abord)
     */
    private function members(string $president, array $others): array
    {
        $members = [$president => 'president'];
        foreach ($others as $person) {
            if (is_string($person) && $person !== '' && !isset($members[$person])) {
                $members[$person] = 'member';
            }
        }
        if (count($members) < 2) {
            throw HttpException::validation(['members' => ['Un jury compte au moins deux membres, président compris.']]);
        }

        return $members;
    }

    /** @param array<string, string> $members */
    private function replaceMembers(string $tenantId, string $establishmentId, string $juryId, array $members): void
    {
        $present = [];
        foreach ($this->membersOf($tenantId, $establishmentId, $juryId) as $member) {
            $present[(string) $member['person_id']] = (int) $member['present'];
        }
        $this->store->delete('hi_jury_members', $tenantId, $establishmentId, ['jury_id' => $juryId]);
        foreach ($members as $person => $role) {
            $this->store->insert('hi_jury_members', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'jury_id' => $juryId, 'person_id' => (string) $person, 'member_role' => $role,
                'present' => $present[(string) $person] ?? 0, 'created_at' => $this->clock->now(),
            ]);
        }
    }

    private function defaultQuorum(string $tenantId, string $establishmentId, int $members): int
    {
        $percent = $this->settings->value($tenantId, $establishmentId, 'assessment.quorum_percent', 50);
        $percent = is_int($percent) || is_float($percent) ? (float) $percent : 50.0;

        return max(1, min($members, (int) ceil($members * $percent / 100)));
    }
}
