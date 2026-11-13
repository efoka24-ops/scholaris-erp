<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Teaching\Domain\TimetableOverlaps;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;
use Scholaris\Modules\Socle\Staff\Contracts\TaughtHoursRecorder;
use Scholaris\Modules\Socle\Staff\Contracts\VacationCalculator;

/**
 * Cahier de textes par séance (EX-ENS-04, API-08) : l'enseignant affecté consigne la séance (date, start_time, end_time, contenu,
 * travaux donnés), la signe électroniquement (empreinte SHA-256) ; le délégué du groupe ou un surveillant contre-signe (EX-ENS-05) ;
 * le chef de département valide les heures du mois (EX-ENS-06). La signature enregistre l'heure au socle (TaughtHoursRecorder) :
 * elle n'y est payable qu'aux conditions de RG-ENS-1/4/5 (HigherPayabilityCheck). Le paiement relève du module des vacations.
 */
final class SessionLogService
{
    public const TABLE = 'hi_session_logs';
    public const DELEGATES = 'hi_group_delegates';
    public const VALIDATIONS = 'hi_hours_validations';

    public function __construct(
        private readonly TeachingStore $store,
        private readonly TeachingAccess $access,
        private readonly ElementResolver $elements,
        private readonly TaughtHoursRecorder $hours,
        private readonly VacationCalculator $calculator,
        private readonly TeachingClock $clock,
    ) {
    }

    /**
     * @param array{assignment_id: string, entry_id: ?string, held_on: string, start_time: string, end_time: string, content: ?string, homework: ?string, attendance_session_id: ?string} $data
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $assignment = $this->store->findOrFail(AssignmentService::TABLE, $tenantId, $establishmentId, $data['assignment_id'], 'Affectation introuvable.');
        $this->assertOwner($principal, $establishmentId, $assignment);
        if ($assignment['status'] !== 'active') {
            throw HttpException::conflict('Affectation terminée.');
        }
        $minutes = TimetableOverlaps::minutes($data['end_time']) - TimetableOverlaps::minutes($data['start_time']);
        if ($minutes <= 0) {
            throw HttpException::validation(['end_time' => ['L\'heure de fin doit suivre l\'heure de début.']]);
        }
        if ($data['held_on'] > $this->clock->today()) {
            throw HttpException::validation(['held_on' => ['Une séance future ne peut pas être consignée.']]);
        }
        $slotId = null;
        if ($data['entry_id'] !== null) {
            $entry = $this->store->findOrFail(TimetableService::ENTRIES, $tenantId, $establishmentId, $data['entry_id'], 'Créneau introuvable.');
            if ($entry['assignment_id'] !== $assignment['id']) {
                throw HttpException::validation(['entry_id' => ['Ce créneau ne relève pas de cette affectation.']]);
            }
            $slotId = (string) $entry['slot_id'];
        }
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(), 'assignment_id' => $assignment['id'], 'entry_id' => $data['entry_id'], 'slot_id' => $slotId,
            'school_id' => $assignment['school_id'], 'department_id' => $assignment['department_id'], 'program_id' => $assignment['program_id'],
            'level_id' => $assignment['level_id'], 'group_id' => $assignment['group_id'], 'element_id' => $assignment['element_id'],
            'person_id' => $assignment['person_id'], 'session_kind' => $assignment['session_kind'], 'held_on' => $data['held_on'],
            'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'minutes' => $minutes, 'content' => $data['content'],
            'homework' => $data['homework'], 'attendance_session_id' => $data['attendance_session_id'], 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.session_log.created', 'hi_session_log', $row['id'], null, $row);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $row['id']);
    }

    /** @return array<string, mixed> */
    public function update(Principal $principal, string $establishmentId, string $id, ?string $content, ?string $homework): array
    {
        $tenantId = $principal->tenantId;
        $log = $this->owned($principal, $establishmentId, $id);
        if ($log['status'] !== 'draft') {
            throw HttpException::conflict('Séance déjà signée : le cahier de textes n\'est plus modifiable.');
        }
        $changes = array_filter(['content' => $content, 'homework' => $homework], static fn (?string $v): bool => $v !== null) + ['updated_at' => $this->clock->now()];
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /**
     * Signature électronique de l'enseignant (empreinte du contenu) puis enregistrement de l'heure au socle.
     *
     * @return array<string, mixed>
     */
    public function sign(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $log = $this->owned($principal, $establishmentId, $id);
        if ($log['status'] !== 'draft') {
            throw HttpException::conflict('Séance déjà signée.');
        }
        if (trim((string) $log['content']) === '') {
            throw HttpException::validation(['content' => ['Le contenu de la séance est obligatoire avant la signature.']]);
        }
        $now = $this->clock->now();
        $signature = hash('sha256', implode('|', [$log['id'], $log['held_on'], $log['start_time'], $log['end_time'], $log['content'], $log['homework'] ?? '', $principal->userId, $now]));
        $hourId = null;
        if ($log['slot_id'] !== null) {
            $assignment = $this->store->findOrFail(AssignmentService::TABLE, $tenantId, $establishmentId, (string) $log['assignment_id']);
            $hourId = $this->hours->record($tenantId, $establishmentId, (string) $assignment['staff_id'], (string) $log['slot_id'], (string) $log['held_on'], (int) $log['minutes']);
        }
        $changes = ['status' => 'signed', 'teacher_signed_at' => $now, 'teacher_signature' => $signature, 'vacation_hour_id' => $hourId, 'updated_at' => $now];
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.session_log.signed', 'hi_session_log', $id, ['status' => 'draft'], $changes);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /**
     * Contre-signature (EX-ENS-05) : délégué du groupe ou surveillant, jamais l'enseignant lui-même.
     *
     * @return array<string, mixed>
     */
    public function countersign(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $log = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Séance introuvable.');
        $me = $this->access->personOf($principal);
        if ($me !== null && $me === $log['person_id']) {
            throw HttpException::forbidden('L\'enseignant ne contre-signe pas sa propre séance.');
        }
        $role = null;
        if ($me !== null && $this->store->count(self::DELEGATES, $tenantId, $establishmentId, ['group_id' => $log['group_id'], 'person_id' => $me]) > 0) {
            $role = 'delegate';
        } else {
            $perimeter = $this->access->perimeter($principal, $establishmentId, TeachingPermissions::COUNTERSIGN);
            if ($perimeter->isEmpty()) {
                throw HttpException::notFound('Séance introuvable.');
            }
            $this->access->assertWritable($principal, $perimeter, $log, 'hi_session_log', $id);
            $role = 'supervisor';
        }
        if ($log['status'] !== 'signed') {
            throw HttpException::conflict('Seule une séance signée par l\'enseignant peut être contre-signée.');
        }
        $changes = ['status' => 'countersigned', 'countersigned_by' => $principal->userId, 'countersigner_role' => $role, 'countersigned_at' => $this->clock->now(), 'updated_at' => $this->clock->now()];
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.session_log.countersigned', 'hi_session_log', $id, ['status' => 'signed'], $changes);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /**
     * Validation mensuelle des heures d'un enseignant par le chef de département (EX-ENS-06) : séances contre-signées du mois dans son
     * département ; les séances non contre-signées restent en attente et sont listées.
     *
     * @return array<string, mixed>
     */
    public function validateMonth(Principal $principal, string $establishmentId, string $personId, string $month): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::HOURS_VALIDATE);
        [$sql, $params] = $this->access->sqlFilter($perimeter, ['school' => 'school_id', 'department' => 'department_id']);
        $logs = array_values(array_filter(
            $this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId], 'held_on, start_time', 5000, $sql, $params),
            static fn (array $log): bool => str_starts_with((string) $log['held_on'], $month . '-'),
        ));
        $byDepartment = [];
        $pending = [];
        foreach ($logs as $log) {
            if ($log['status'] === 'countersigned') {
                $byDepartment[(string) $log['department_id']][] = $log;
            } elseif (in_array($log['status'], ['draft', 'signed'], true)) {
                $pending[] = ['id' => $log['id'], 'held_on' => $log['held_on'], 'status' => $log['status']];
            }
        }
        if ($byDepartment === []) {
            throw HttpException::conflict('Aucune séance contre-signée à valider pour ce mois dans votre périmètre.');
        }
        $now = $this->clock->now();
        $validations = [];
        foreach ($byDepartment as $departmentId => $departmentLogs) {
            if ($this->store->count(self::VALIDATIONS, $tenantId, $establishmentId, ['department_id' => $departmentId, 'person_id' => $personId, 'period_month' => $month]) > 0) {
                throw HttpException::conflict('Les heures de ce mois sont déjà validées pour ce département.');
            }
            $minutes = ['cm' => 0, 'td' => 0, 'tp' => 0, 'other' => 0];
            foreach ($departmentLogs as $log) {
                $kind = (string) $log['session_kind'];
                $minutes[$kind] = ($minutes[$kind] ?? 0) + (int) $log['minutes'];
            }
            $validation = [
                'id' => (string) new Ulid(), 'school_id' => $departmentLogs[0]['school_id'], 'department_id' => $departmentId, 'person_id' => $personId,
                'period_month' => $month, 'log_count' => count($departmentLogs), 'minutes_total' => array_sum($minutes), 'cm_minutes' => $minutes['cm'],
                'td_minutes' => $minutes['td'], 'tp_minutes' => $minutes['tp'], 'hetd_milli' => $this->calculator->hetdMilli($tenantId, $establishmentId, $minutes),
                'validated_by' => $principal->userId, 'validated_at' => $now,
            ];
            $this->store->transaction(function () use ($tenantId, $establishmentId, $validation, $departmentLogs, $principal, $now): void {
                $this->store->insert(self::VALIDATIONS, $tenantId, $establishmentId, $validation);
                foreach ($departmentLogs as $log) {
                    $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => 'validated', 'validation_id' => $validation['id'], 'validated_by' => $principal->userId, 'validated_at' => $now, 'updated_at' => $now], ['id' => (string) $log['id']]);
                }
            });
            $this->access->audit($tenantId, $principal->userId, 'higher.hours.validated', 'hi_hours_validation', $validation['id'], null, $validation);
            $validations[] = $validation;
        }

        return ['validations' => $validations, 'pending' => $pending];
    }

    /**
     * Synthèse des heures du mois par enseignant dans le périmètre (écran de validation).
     *
     * @return list<array<string, mixed>>
     */
    public function monthSummary(Principal $principal, string $establishmentId, string $month, ?string $school): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::LOG_READ, $school);
        [$sql, $params] = $this->access->sqlFilter($perimeter);
        $rows = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, [], 'person_id, held_on', 10000, $sql, $params) as $log) {
            if (!str_starts_with((string) $log['held_on'], $month . '-')) {
                continue;
            }
            $key = (string) $log['person_id'];
            $rows[$key] ??= ['person_id' => $key, 'month' => $month, 'logs' => 0, 'minutes' => ['cm' => 0, 'td' => 0, 'tp' => 0, 'other' => 0], 'by_status' => []];
            ++$rows[$key]['logs'];
            $kind = (string) $log['session_kind'];
            $rows[$key]['minutes'][$kind] = ($rows[$key]['minutes'][$kind] ?? 0) + (int) $log['minutes'];
            $status = (string) $log['status'];
            $rows[$key]['by_status'][$status] = ($rows[$key]['by_status'][$status] ?? 0) + 1;
        }
        foreach ($rows as $key => $row) {
            $rows[$key]['hetd_milli'] = $this->calculator->hetdMilli($tenantId, $establishmentId, $row['minutes']);
        }

        return array_values($rows);
    }

    /** @return array<string, mixed> */
    public function designateDelegate(Principal $principal, string $establishmentId, string $groupId, string $personId): array
    {
        $tenantId = $principal->tenantId;
        $place = $this->elements->group($tenantId, $establishmentId, $groupId);
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::DELEGATE_MANAGE);
        $this->access->assertWritable($principal, $perimeter, $place, 'hi_group', $groupId);
        $row = [
            'id' => (string) new Ulid(), 'school_id' => $place['school_id'], 'department_id' => $place['department_id'], 'program_id' => $place['program_id'],
            'level_id' => $place['level_id'], 'group_id' => $groupId, 'person_id' => $personId, 'designated_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ];
        $this->store->insert(self::DELEGATES, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.delegate.designated', 'hi_group', $groupId, null, $row);

        return $row;
    }

    public function removeDelegate(Principal $principal, string $establishmentId, string $id): void
    {
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail(self::DELEGATES, $tenantId, $establishmentId, $id, 'Délégué introuvable.');
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::DELEGATE_MANAGE);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_group', (string) $row['group_id']);
        $this->store->delete(self::DELEGATES, $tenantId, $establishmentId, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.delegate.removed', 'hi_group', (string) $row['group_id'], $row, null);
    }

    /** @return array<string, mixed> */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $log = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Séance introuvable.');
        $me = $this->access->personOf($principal);
        if ($me !== null && ($me === $log['person_id'] || $this->store->count(self::DELEGATES, $principal->tenantId, $establishmentId, ['group_id' => $log['group_id'], 'person_id' => $me]) > 0)) {
            return $log;
        }
        $this->access->assertReadable($this->access->require($principal, $establishmentId, array_merge(TeachingPermissions::LOG_READ, TeachingPermissions::COUNTERSIGN)), $log);

        return $log;
    }

    /** @param array<string, mixed> $assignment */
    private function assertOwner(Principal $principal, string $establishmentId, array $assignment): void
    {
        if (!$this->access->has($principal, $establishmentId, TeachingPermissions::LOG_WRITE)) {
            throw HttpException::forbidden('Permission requise : higher.session_log.write.');
        }
        if ($this->access->personOf($principal) !== $assignment['person_id']) {
            $this->access->audit($principal->tenantId, $principal->userId, 'higher.scope.denied', 'hi_assignment', (string) $assignment['id'], null, ['reason' => 'not_assigned']);

            throw HttpException::forbidden('Vous n\'êtes pas affecté à cet élément constitutif et à ce groupe.');
        }
    }

    /** @return array<string, mixed> */
    private function owned(Principal $principal, string $establishmentId, string $id): array
    {
        $log = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Séance introuvable.');
        $this->assertOwner($principal, $establishmentId, ['id' => $log['assignment_id'], 'person_id' => $log['person_id']]);

        return $log;
    }
}
