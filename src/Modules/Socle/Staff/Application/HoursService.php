<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\TaughtHoursRecorder;
use Scholaris\Modules\Socle\Staff\Domain\SessionKind;
use Scholaris\Modules\Socle\Staff\Domain\StaffKind;
use Scholaris\Modules\Socle\Staff\Domain\StaffStatus;
use Scholaris\Modules\Socle\Staff\Domain\VacationCalc;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStore;

/**
 * Heures réalisées, séance par séance. Une heure passe de « déclarée » à « validée » (ou « rejetée »). Elle n'est payable que si
 * l'enseignant a émargé, que le cahier de textes est renseigné et qu'un responsable, autre que le déclarant, l'a contre-signée (RG-ENS-1).
 */
final class HoursService implements TaughtHoursRecorder
{
    public const MAX_MINUTES = 720;

    public function __construct(
        private readonly PdoStore $store,
        private readonly StaffClock $clock,
        private readonly StaffService $staff,
        private readonly ContractService $contracts,
        private readonly RateService $rates,
        private readonly PayabilityChecks $checks,
    ) {
    }

    /**
     * @param array<string, mixed> $data held_on, minutes, session_kind, assignment_id, slot_id, content
     * @return array<string, mixed>
     */
    public function declare(string $tenantId, string $establishmentId, string $staffId, array $data, string $actorId, bool $signedByTeacher): array
    {
        $member = $this->staff->get($tenantId, $establishmentId, $staffId);
        if ($member['status'] !== StaffStatus::Active->value) {
            throw HttpException::conflict('Seul un membre du personnel actif peut déclarer des heures.');
        }
        $heldOn = (string) $data['held_on'];
        if ($heldOn > $this->clock->today()) {
            throw HttpException::validation(['held_on' => ['Une séance à venir ne peut pas être déclarée.']]);
        }
        $minutes = (int) $data['minutes'];
        $assignmentId = isset($data['assignment_id']) ? (string) $data['assignment_id'] : null;
        $level = isset($data['level']) ? (string) $data['level'] : null;
        if ($assignmentId !== null) {
            $assignment = $this->store->find('staff_assignments', $tenantId, $assignmentId);
            if ($assignment === null || $assignment['staff_id'] !== $staffId || $assignment['establishment_id'] !== $establishmentId) {
                throw HttpException::validation(['assignment_id' => ['Affectation inconnue pour cet enseignant.']]);
            }
            $level ??= $assignment['level'] === null ? null : (string) $assignment['level'];
        }
        $slotId = isset($data['slot_id']) ? (string) $data['slot_id'] : null;
        $now = $this->clock->now();
        $values = [
            'assignment_id' => $assignmentId, 'level' => $level, 'held_on' => $heldOn, 'minutes' => $minutes,
            'session_kind' => (string) ($data['session_kind'] ?? SessionKind::Td->value), 'content' => $data['content'] ?? null,
            'status' => 'declared', 'declared_by' => $actorId, 'teacher_signed_at' => $signedByTeacher ? $now : null,
            'validated_by' => null, 'countersigned_at' => null, 'rejection_reason' => null, 'updated_at' => $now,
        ];

        $existing = $slotId === null ? null : $this->findBySlot($tenantId, $staffId, $slotId, $heldOn);
        if ($existing !== null) {
            if ($existing['status'] !== 'rejected') {
                throw HttpException::conflict('Cette séance est déjà déclarée pour cet enseignant.');
            }
            $this->store->update('vacation_hours', $tenantId, (string) $existing['id'], $values);

            return $this->get($tenantId, $establishmentId, (string) $existing['id']);
        }

        $id = (string) new Ulid();
        $this->store->insert('vacation_hours', $values + [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'staff_id' => $staffId,
            'contract_id' => null, 'slot_id' => $slotId, 'source' => 'manual', 'statement_id' => null, 'created_at' => $now,
        ]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    public function record(string $tenantId, string $establishmentId, string $staffId, string $slotId, string $date, int $minutes): string
    {
        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw HttpException::validation(['minutes' => ['La durée d\'une séance doit être comprise entre 1 et ' . self::MAX_MINUTES . ' minutes.']]);
        }
        $this->staff->get($tenantId, $establishmentId, $staffId);
        $existing = $this->findBySlot($tenantId, $staffId, $slotId, $date);
        $now = $this->clock->now();
        if ($existing !== null) {
            if ($existing['status'] === 'declared' && (int) $existing['minutes'] !== $minutes) {
                $this->store->update('vacation_hours', $tenantId, (string) $existing['id'], ['minutes' => $minutes, 'teacher_signed_at' => null, 'updated_at' => $now]);
            }

            return (string) $existing['id'];
        }
        $id = (string) new Ulid();
        $this->store->insert('vacation_hours', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'staff_id' => $staffId,
            'assignment_id' => null, 'contract_id' => null, 'slot_id' => $slotId, 'held_on' => $date, 'minutes' => $minutes,
            'session_kind' => SessionKind::Td->value, 'content' => null, 'status' => 'declared', 'source' => 'scheduling',
            'declared_by' => null, 'teacher_signed_at' => null, 'validated_by' => null, 'countersigned_at' => null,
            'rejection_reason' => null, 'statement_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * Émargement de l'enseignant (et cahier de textes). Réservé, par le contrôleur, à l'enseignant concerné.
     *
     * @param array<string, mixed> $data content, session_kind
     * @return array<string, mixed>
     */
    public function sign(string $tenantId, string $establishmentId, string $id, array $data): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'declared') {
            throw HttpException::conflict('Seule une heure déclarée peut être émargée.');
        }
        $values = ['teacher_signed_at' => $this->clock->now(), 'updated_at' => $this->clock->now()];
        foreach (['content', 'session_kind'] as $field) {
            if (isset($data[$field])) {
                $values[$field] = $data[$field];
            }
        }
        $this->store->update('vacation_hours', $tenantId, $id, $values);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function validate(string $tenantId, string $establishmentId, string $id, string $validatorId): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'declared') {
            throw HttpException::conflict('Seule une heure déclarée peut être validée.');
        }
        if ($row['teacher_signed_at'] === null) {
            throw HttpException::conflict('L\'enseignant n\'a pas émargé cette séance : l\'heure n\'est pas payable.');
        }
        if ($row['content'] === null || trim((string) $row['content']) === '') {
            throw HttpException::conflict('Le cahier de textes n\'est pas renseigné pour cette séance.');
        }
        if ($row['declared_by'] === $validatorId) {
            throw HttpException::forbidden('Une heure ne peut pas être validée par celui qui l\'a déclarée.');
        }
        $member = $this->staff->get($tenantId, $establishmentId, (string) $row['staff_id']);
        $contractId = null;
        if ($member['kind'] !== StaffKind::Permanent->value) {
            $contract = $this->contracts->activeOn($tenantId, $establishmentId, (string) $row['staff_id'], (string) $row['held_on']);
            if ($contract === null) {
                throw HttpException::conflict('Aucun contrat de vacation actif ne couvre cette séance.');
            }
            $contractId = (string) $contract['id'];
            $this->assertWithinCaps($tenantId, $establishmentId, $row, $contract, $member);
        }
        $this->checks->assertHourPayable($this->payable($tenantId, $row, $member), $validatorId);
        $this->store->update('vacation_hours', $tenantId, $id, [
            'status' => 'validated', 'contract_id' => $contractId, 'validated_by' => $validatorId,
            'countersigned_at' => $this->clock->now(), 'rejection_reason' => null, 'updated_at' => $this->clock->now(),
        ]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function reject(string $tenantId, string $establishmentId, string $id, string $reason, string $validatorId): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'declared') {
            throw HttpException::conflict('Seule une heure déclarée peut être rejetée.');
        }
        $this->store->update('vacation_hours', $tenantId, $id, [
            'status' => 'rejected', 'validated_by' => $validatorId, 'rejection_reason' => $reason, 'updated_at' => $this->clock->now(),
        ]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->store->find('vacation_hours', $tenantId, $id);
        if ($row === null || $row['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Heure introuvable.');
        }

        return $row;
    }

    /**
     * @param list<string>|null $restrictTo fiches visibles ; null = tout l'établissement
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    public function search(string $tenantId, string $establishmentId, ListQuery $query, ?array $restrictTo): array
    {
        if ($restrictTo === []) {
            return [[], null];
        }
        $where = ['tenant_id = ?', 'establishment_id = ?'];
        $params = [$tenantId, $establishmentId];
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $where[] = 'id > ?';
            $params[] = $after;
        }
        foreach (['staff_id', 'status', 'source', 'assignment_id'] as $field) {
            if (isset($query->filters[$field])) {
                $where[] = $field . ' = ?';
                $params[] = $query->filters[$field];
            }
        }
        if (isset($query->filters['period'])) {
            try {
                [$first, $last] = VacationCalc::monthBounds($query->filters['period']);
            } catch (\InvalidArgumentException $e) {
                throw HttpException::validation(['filter[period]' => [$e->getMessage()]]);
            }
            array_push($where, 'held_on >= ?', 'held_on <= ?');
            array_push($params, $first, $last);
        }
        if ($restrictTo !== null) {
            $where[] = 'staff_id IN (' . implode(', ', array_fill(0, count($restrictTo), '?')) . ')';
            array_push($params, ...$restrictTo);
        }
        $rows = $this->store->rows(sprintf('SELECT * FROM vacation_hours WHERE %s ORDER BY id LIMIT %d', implode(' AND ', $where), $query->perPage + 1), $params);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    /**
     * Vue d'une heure pour les contrôles de payabilité enfichables (D16).
     *
     * @param array<string, mixed> $row ligne de vacation_hours
     * @param array<string, mixed> $member fiche du personnel
     */
    public function payable(string $tenantId, array $row, array $member): PayableHour
    {
        $assignment = $row['assignment_id'] === null ? null : $this->store->find('staff_assignments', $tenantId, (string) $row['assignment_id']);
        $text = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        return new PayableHour(
            (string) $row['id'],
            $tenantId,
            (string) $row['establishment_id'],
            (string) $row['staff_id'],
            (string) $member['person_id'],
            (string) $member['kind'],
            (string) $row['held_on'],
            (int) $row['minutes'],
            (string) $row['session_kind'],
            $text($row['assignment_id']),
            $text($assignment['element_ref'] ?? null),
            $text($assignment['group_ref'] ?? null),
            $text($row['level'] ?? null),
            $text($row['slot_id']),
            $text($row['content']),
            $text($row['teacher_signed_at']),
            $text($row['declared_by']),
            (string) $row['status'],
        );
    }

    /** @return array<string, mixed>|null */
    private function findBySlot(string $tenantId, string $staffId, string $slotId, string $date): ?array
    {
        return $this->store->where('vacation_hours', $tenantId, ['staff_id' => $staffId, 'slot_id' => $slotId, 'held_on' => $date], 'id', 1)[0] ?? null;
    }

    /**
     * Plafond du contrat (volume total) et plafond mensuel de la catégorie.
     *
     * @param array<string, mixed> $hour
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $member
     */
    private function assertWithinCaps(string $tenantId, string $establishmentId, array $hour, array $contract, array $member): void
    {
        $minutes = (int) $hour['minutes'];
        if ($contract['max_minutes'] !== null) {
            $done = $this->store->rows('SELECT COALESCE(SUM(minutes), 0) AS total FROM vacation_hours WHERE tenant_id = ? AND contract_id = ? AND status = ?', [$tenantId, $contract['id'], 'validated']);
            if ((int) $done[0]['total'] + $minutes > (int) $contract['max_minutes']) {
                throw HttpException::conflict('Le volume horaire du contrat serait dépassé : heure non validable.');
            }
        }
        $rate = $this->rates->forCategory($tenantId, $establishmentId, $member['category'] === null ? null : (string) $member['category']);
        if ($rate !== null && $rate['monthly_cap_minutes'] !== null) {
            [$first, $last] = VacationCalc::monthBounds(substr((string) $hour['held_on'], 0, 7));
            $done = $this->store->rows(
                'SELECT COALESCE(SUM(minutes), 0) AS total FROM vacation_hours WHERE tenant_id = ? AND establishment_id = ? AND staff_id = ? AND status = ? AND held_on >= ? AND held_on <= ?',
                [$tenantId, $establishmentId, $hour['staff_id'], 'validated', $first, $last],
            );
            if ((int) $done[0]['total'] + $minutes > (int) $rate['monthly_cap_minutes']) {
                throw HttpException::conflict('Le plafond mensuel de la catégorie serait dépassé : heure non validable.');
            }
        }
    }
}
