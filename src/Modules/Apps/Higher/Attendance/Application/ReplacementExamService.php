<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Attendance\Infrastructure\AttendanceStore;

/**
 * Épreuve de remplacement après une absence justifiée à l'examen (ABJ, RG-NOT-5, T425) : demandée par la scolarité sur justificatif
 * validé, programmée par le service des examens (date, heures, salle), puis tenue ou annulée. Tracée.
 */
final class ReplacementExamService
{
    public const TABLE = 'hi_replacement_exams';

    public function __construct(
        private readonly AttendanceStore $store,
        private readonly AttendanceAccess $access,
        private readonly Placement $placement,
        private readonly AttendanceClock $clock,
    ) {
    }

    /**
     * @param array{person_id: string, element_id: string, group_id: string, academic_year_id: string, absence_date: string, justification_ref: string, reason: string} $data
     * @return array<string, mixed>
     */
    public function request(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $place = $this->placement->group($tenantId, $establishmentId, $data['group_id']);
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, AttendancePermissions::MANAGE), $place, 'hi_replacement_exam', $data['person_id']);
        $now = $this->clock->now();
        $row = $place + $data + ['id' => (string) new Ulid(), 'status' => 'requested', 'requested_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.replacement_exam.requested', 'hi_replacement_exam', $row['id'], null, $row);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $row['id']);
    }

    /** @return array<string, mixed> */
    public function schedule(Principal $principal, string $establishmentId, string $id, string $date, string $start, string $end, ?string $roomId): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, AttendancePermissions::EXAMS);
        if (!in_array($row['status'], ['requested', 'scheduled'], true)) {
            throw HttpException::conflict('Épreuve déjà tenue ou annulée.');
        }
        if ($end <= $start) {
            throw HttpException::validation(['end_time' => ['L\'heure de fin doit suivre l\'heure de début.']]);
        }
        $changes = ['exam_date' => $date, 'start_time' => $start, 'end_time' => $end, 'room_id' => $roomId, 'status' => 'scheduled', 'scheduled_by' => $principal->userId, 'updated_at' => $this->clock->now()];
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.replacement_exam.scheduled', 'hi_replacement_exam', $id, ['status' => $row['status']], $changes);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function close(Principal $principal, string $establishmentId, string $id, string $status): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, AttendancePermissions::EXAMS);
        if ($status === 'done' && $row['status'] !== 'scheduled') {
            throw HttpException::conflict('Seule une épreuve programmée peut être déclarée tenue.');
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => $status, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.replacement_exam.' . $status, 'hi_replacement_exam', $id, ['status' => $row['status']], ['status' => $status]);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function writable(Principal $principal, string $establishmentId, string $id, array $permissions): array
    {
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Épreuve de remplacement introuvable.');
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, $permissions), $row, 'hi_replacement_exam', $id);

        return $row;
    }
}
