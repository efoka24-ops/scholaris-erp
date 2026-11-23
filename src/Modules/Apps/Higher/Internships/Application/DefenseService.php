<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Internships\Domain\DefenseReadiness;
use Scholaris\Modules\Apps\Higher\Internships\Domain\JuryRules;
use Scholaris\Modules\Apps\Higher\Internships\Domain\Slot;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/**
 * Programmation des soutenances (API-18, EX-STG-05, R16) : RG-STG-1 (convention signée, attestation, mémoire, quitus), RG-STG-2
 * (jury), conflits de créneau (salle, membre du jury, étudiant déjà programmé). Une reprogrammation repasse la soutenance à
 * « programmée » : la convocation est à renvoyer. Convocation, note et PV : DefenseSession.
 */
final class DefenseService
{
    public const TABLE = 'hi_defenses';
    public const JURY = 'hi_defense_jury_members';
    public const SCHEDULED = 'scheduled';
    public const CONVENED = 'convened';
    public const GRADED = 'graded';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';
    public const ACTIVE = [self::SCHEDULED, self::CONVENED, self::GRADED];
    public const JURY_MIN_KEY = 'higher.defense.jury_min';

    public function __construct(
        private readonly InternshipStore $store,
        private readonly InternshipAccess $access,
        private readonly InternshipClock $clock,
        private readonly ConventionService $conventions,
        private readonly OrgUnitSettingsReader $settings,
    ) {
    }

    /**
     * @param array{held_on: string, start_time: string, end_time: string, room: string} $slot
     * @param list<array{person_id: string, jury_role: string, display_name: ?string}> $jury
     * @return array<string, mixed>
     */
    public function schedule(Principal $principal, string $establishmentId, string $internshipId, array $slot, array $jury): array
    {
        $internship = $this->conventions->writable($principal, $establishmentId, $internshipId, InternshipAccess::DEFENSE);
        foreach ($this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['internship_id' => $internshipId]) as $existing) {
            if (in_array($existing['status'], self::ACTIVE, true) || $existing['status'] === self::CLOSED) {
                throw HttpException::conflict('Une soutenance est déjà programmée pour ce stage.');
            }
        }
        $missing = DefenseReadiness::missing($this->conventions->readiness($principal->tenantId, $internship));
        if ($missing !== []) {
            throw HttpException::conflict('Soutenance non programmable (RG-STG-1), conditions manquantes : ' . implode(', ', $missing) . '.');
        }
        $this->checkJury($principal->tenantId, $establishmentId, $internship, $jury);
        $this->checkConflicts($principal->tenantId, $establishmentId, $slot, $jury, null);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->transaction(function () use ($principal, $establishmentId, $internship, $slot, $jury, $id, $now): void {
            $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, [
                'id' => $id, 'internship_id' => (string) $internship['id'], 'person_id' => (string) $internship['person_id'],
                'school_id' => (string) $internship['school_id'], 'department_id' => $internship['department_id'], 'program_id' => (string) $internship['program_id'],
                'level_id' => (string) $internship['level_id'], 'status' => self::SCHEDULED, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
            ] + $slot);
            $this->writeJury($principal->tenantId, $establishmentId, $id, $jury, $now);
        });
        $this->access->audit($principal, 'higher.defense.scheduled', 'hi_defense', $id, null, $slot + ['jury' => array_column($jury, 'person_id')]);

        return $this->row($principal->tenantId, $establishmentId, $id);
    }

    /**
     * @param array{held_on: string, start_time: string, end_time: string, room: string} $slot
     * @param list<array{person_id: string, jury_role: string, display_name: ?string}>|null $jury null = jury inchangé
     * @return array<string, mixed>
     */
    public function reschedule(Principal $principal, string $establishmentId, string $id, array $slot, ?array $jury): array
    {
        $defense = $this->writable($principal, $establishmentId, $id, InternshipAccess::DEFENSE);
        if (!in_array($defense['status'], [self::SCHEDULED, self::CONVENED], true)) {
            throw HttpException::conflict('Seule une soutenance programmée ou convoquée peut être reprogrammée.');
        }
        $internship = $this->store->findOrFail(ConventionService::TABLE, $principal->tenantId, $establishmentId, (string) $defense['internship_id']);
        $members = $jury ?? $this->jury($principal->tenantId, $establishmentId, $id);
        $this->checkJury($principal->tenantId, $establishmentId, $internship, $members);
        $this->checkConflicts($principal->tenantId, $establishmentId, $slot, $members, $id);
        $now = $this->clock->now();
        $this->store->transaction(function () use ($principal, $establishmentId, $id, $slot, $jury, $now): void {
            $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $slot + ['status' => self::SCHEDULED, 'convened_at' => null, 'updated_at' => $now], ['id' => $id]);
            if ($jury !== null) {
                $this->store->delete(self::JURY, $principal->tenantId, $establishmentId, ['defense_id' => $id]);
                $this->writeJury($principal->tenantId, $establishmentId, $id, $jury, $now);
            }
        });
        $this->access->audit($principal, 'higher.defense.rescheduled', 'hi_defense', $id, $defense, $slot);

        return $this->row($principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function cancel(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        $defense = $this->writable($principal, $establishmentId, $id, InternshipAccess::DEFENSE);
        if (!in_array($defense['status'], [self::SCHEDULED, self::CONVENED], true)) {
            throw HttpException::conflict('Seule une soutenance non notée peut être annulée.');
        }
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, ['status' => self::CANCELLED, 'cancel_reason' => $reason, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'higher.defense.cancelled', 'hi_defense', $id, ['status' => $defense['status']], ['reason' => $reason]);

        return $this->row($principal->tenantId, $establishmentId, $id);
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function writable(Principal $principal, string $establishmentId, string $id, array $permissions): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $row = $this->row($principal->tenantId, $establishmentId, $id);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_defense', $id);

        return $row;
    }

    /** @return array<string, mixed> */
    public function row(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Soutenance introuvable.');
    }

    /** @return list<array{person_id: string, jury_role: string, display_name: string}> */
    public function jury(string $tenantId, string $establishmentId, string $defenseId): array
    {
        return array_map(static fn (array $m): array => [
            'person_id' => (string) $m['person_id'], 'jury_role' => (string) $m['jury_role'], 'display_name' => (string) $m['display_name'],
        ], $this->store->select(self::JURY, $tenantId, $establishmentId, ['defense_id' => $defenseId], 'jury_role, display_name'));
    }

    /**
     * @param array<string, mixed> $internship
     * @param list<array{person_id: string, jury_role: string, display_name: ?string}> $jury
     */
    private function checkJury(string $tenantId, string $establishmentId, array $internship, array $jury): void
    {
        $minimum = $this->settings->valueForOrgUnit($tenantId, $establishmentId, (string) $internship['school_id'], self::JURY_MIN_KEY, JuryRules::DEFAULT_MINIMUM);
        $violations = JuryRules::violations($jury, (string) $internship['supervisor_person_id'], is_numeric($minimum) ? max(1, (int) $minimum) : JuryRules::DEFAULT_MINIMUM);
        if ($violations !== []) {
            throw HttpException::validation(['jury' => $violations], 'Jury non conforme (RG-STG-2).');
        }
    }

    /**
     * Conflits : même jour, créneaux qui se chevauchent, même salle ou un membre du jury commun.
     *
     * @param array{held_on: string, start_time: string, end_time: string, room: string} $slot
     * @param list<array{person_id: string, jury_role: string, display_name: ?string}> $jury
     */
    private function checkConflicts(string $tenantId, string $establishmentId, array $slot, array $jury, ?string $ignoreId): void
    {
        if (!Slot::validTime($slot['start_time']) || !Slot::validTime($slot['end_time']) || $slot['end_time'] <= $slot['start_time']) {
            throw HttpException::validation(['end_time' => ['Créneau invalide : fin après le début, au format HH:MM.']]);
        }
        $people = array_column($jury, 'person_id');
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['held_on' => $slot['held_on']]) as $other) {
            if ($other['id'] === $ignoreId || !in_array($other['status'], self::ACTIVE, true)
                || !Slot::overlaps($slot['start_time'], $slot['end_time'], (string) $other['start_time'], (string) $other['end_time'])) {
                continue;
            }
            if (mb_strtolower(trim((string) $other['room'])) === mb_strtolower(trim($slot['room']))) {
                throw HttpException::conflict(sprintf('Salle %s déjà occupée de %s à %s par une autre soutenance.', $slot['room'], $other['start_time'], $other['end_time']));
            }
            $busy = array_intersect($people, array_column($this->jury($tenantId, $establishmentId, (string) $other['id']), 'person_id'));
            if ($busy !== []) {
                throw HttpException::conflict(sprintf('Un membre du jury siège déjà à une autre soutenance de %s à %s.', $other['start_time'], $other['end_time']));
            }
        }
    }

    /** @param list<array{person_id: string, jury_role: string, display_name: ?string}> $jury */
    private function writeJury(string $tenantId, string $establishmentId, string $defenseId, array $jury, string $now): void
    {
        foreach ($jury as $member) {
            $this->store->insert(self::JURY, $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'defense_id' => $defenseId, 'person_id' => $member['person_id'],
                'display_name' => $member['display_name'] ?? '', 'jury_role' => $member['jury_role'], 'created_at' => $now,
            ]);
        }
    }
}
