<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TaughtSessionInfo;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TaughtSessions;
use Scholaris\Modules\Apps\Higher\Vacations\Domain\VacationStatus;
use Scholaris\Modules\Apps\Higher\Vacations\Infrastructure\VacationStore;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/**
 * État mensuel des vacations d'un enseignant (EX-ENS-06, DOC-25) : un état par département, établi par le chef de département ou le
 * comptable à partir des seules séances validées (RG-ENS-1 : aucune heure validée = refus). Recalculable tant qu'il n'est pas visé.
 */
final class StatementService
{
    public const TABLE = 'hi_vacation_statements';
    public const LINES = 'hi_vacation_statement_lines';

    public function __construct(
        private readonly VacationStore $store,
        private readonly VacationAccess $access,
        private readonly TaughtSessions $sessions,
        private readonly StaffDirectory $staff,
        private readonly StatementCalculator $calculator,
        private readonly HoldEvaluator $holds,
        private readonly VacationNotices $notices,
        private readonly VacationClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> états établis ou recalculés */
    public function prepare(Principal $principal, string $establishmentId, string $personId, string $month, ?string $departmentId): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, VacationPermissions::PREPARE);
        $all = $this->sessions->ofTeacherInMonth($tenantId, $establishmentId, $personId, $month);
        $mine = array_values(array_filter($all, fn (TaughtSessionInfo $s): bool => ($departmentId === null || $s->departmentId === $departmentId)
            && $this->access->covers($perimeter, ['school_id' => $s->schoolId, 'department_id' => $s->departmentId])));
        if ($mine === []) {
            throw HttpException::notFound('Aucune séance de cet enseignant pour ce mois dans votre périmètre.');
        }
        $byDepartment = [];
        $pending = [];
        foreach ($mine as $session) {
            if ($session->isValidated()) {
                $byDepartment[$session->departmentId][] = $session;
            } elseif ($session->status !== 'rejected') {
                $pending[$session->departmentId] = ($pending[$session->departmentId] ?? 0) + 1;
            }
        }
        if ($byDepartment === []) {
            throw HttpException::conflict(sprintf('RG-ENS-1 : aucune heure validée par le chef de département pour %s (%d séance(s) en attente de validation).', $month, array_sum($pending)));
        }
        $staff = $this->staff->find($tenantId, $personId);
        $out = [];
        foreach ($byDepartment as $department => $sessions) {
            $out[] = $this->save($principal, $establishmentId, $personId, $month, (string) $department, $sessions, $staff, $pending[$department] ?? 0);
        }

        return $out;
    }

    /** @return array<string, mixed> état avec ses lignes, lisible par le périmètre ou par l'enseignant lui-même */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('État introuvable.');
        $perimeter = $this->access->perimeter($principal, $establishmentId, VacationPermissions::READ);
        $own = $row['person_id'] === $this->access->personOf($principal) && !$this->access->perimeter($principal, $establishmentId, VacationPermissions::READ_OWN)->isEmpty();
        if (!$own && ($perimeter->isEmpty() || !$this->access->covers($perimeter, $row))) {
            throw HttpException::notFound('État introuvable.');
        }

        return $row + ['lines' => $this->store->select(self::LINES, $principal->tenantId, $establishmentId, ['statement_id' => $id], 'held_on, id', 5000)];
    }

    /**
     * @param list<TaughtSessionInfo> $sessions
     * @return array<string, mixed>
     */
    private function save(Principal $principal, string $establishmentId, string $personId, string $month, string $departmentId, array $sessions, ?StaffInfo $staff, int $pending): array
    {
        $tenantId = $principal->tenantId;
        $existing = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['department_id' => $departmentId, 'person_id' => $personId, 'period_month' => $month], 'id', 1)[0] ?? null;
        if ($existing !== null && !VacationStatus::recomputable((string) $existing['status'])) {
            throw HttpException::conflict('L\'état de ce mois est déjà visé, approuvé ou payé : il ne peut plus être recalculé.');
        }
        $computed = $this->calculator->compute($tenantId, $establishmentId, $staff, $sessions, $principal->userId);
        $now = $this->clock->now();
        $id = $existing === null ? (string) new Ulid() : (string) $existing['id'];
        $row = [
            'school_id' => $sessions[0]->schoolId, 'department_id' => $departmentId, 'person_id' => $personId,
            'staff_id' => $staff->staffId ?? $sessions[0]->staffId, 'period_month' => $month,
        ] + $computed['totals'] + [
            'pending_sessions' => $pending, 'status' => VacationStatus::DRAFT, 'prepared_by' => $principal->userId, 'prepared_at' => $now,
            'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null, 'updated_at' => $now,
        ];
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $row, $existing, $computed, $now): void {
            if ($existing === null) {
                $this->store->insert(self::TABLE, $tenantId, $establishmentId, ['id' => $id] + $row + ['created_at' => $now]);
            } else {
                $this->store->update(self::TABLE, $tenantId, $establishmentId, $row, ['id' => $id]);
                $this->store->delete(self::LINES, $tenantId, $establishmentId, ['statement_id' => $id]);
            }
            foreach ($computed['lines'] as $line) {
                $this->store->insert(self::LINES, $tenantId, $establishmentId, ['id' => (string) new Ulid(), 'statement_id' => $id] + $line + ['created_at' => $now]);
            }
        });
        $this->holds->refresh($this->store->find(self::TABLE, $tenantId, $establishmentId, $id) ?? [], $principal->userId);
        $statement = $this->store->find(self::TABLE, $tenantId, $establishmentId, $id) ?? [];
        $this->access->audit($tenantId, $principal->userId, $existing === null ? 'higher.vacation.prepared' : 'higher.vacation.recomputed', $id, $existing, $row);
        $this->notices->hoursValidated($statement);

        return $statement;
    }
}
