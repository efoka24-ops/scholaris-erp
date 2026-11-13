<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Infrastructure\Db;
use Scholaris\Modules\Socle\Attendance\Infrastructure\DisciplineRepository;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Incidents et sanctions. Le surveillant signale et propose ; la sanction ne prend effet qu'après validation par une autre
 * personne (module Audit, Approvals). Le barème des sanctions est celui de l'établissement (attendance.sanction_scale).
 */
final class DisciplineService
{
    public const KIND_APPROVAL = 'sanction.approval';

    public function __construct(
        private readonly Db $db,
        private readonly DisciplineRepository $repository,
        private readonly EnrollmentDirectory $enrollments,
        private readonly AttendanceSettings $settings,
        private readonly Approvals $approvals,
        private readonly AuditLog $audit,
        private readonly AttendanceClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data person_id, occurred_on, category, description
     * @return array<string, mixed>
     */
    public function reportIncident(Principal $principal, string $establishmentId, array $data): array
    {
        $this->requireEnrolled($principal->tenantId, $establishmentId, (string) $data['person_id']);
        $id = (string) new Ulid();
        $this->repository->insert(DisciplineRepository::INCIDENTS, [
            'id' => $id, 'tenant_id' => $principal->tenantId, 'establishment_id' => $establishmentId, 'person_id' => $data['person_id'],
            'occurred_on' => $data['occurred_on'], 'category' => $data['category'], 'description' => $data['description'],
            'reported_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->audit->append($principal->tenantId, $principal->userId, 'attendance.incident.reported', 'incident', $id, null, ['person_id' => $data['person_id'], 'category' => $data['category']]);

        return $this->mustFind(DisciplineRepository::INCIDENTS, $principal->tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $data person_id, kind, reason, incident_id
     * @return array<string, mixed>
     */
    public function proposeSanction(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $scale = $this->settings->sanctionScale($tenantId, $establishmentId);
        if (!in_array($data['kind'], $scale, true)) {
            throw HttpException::validation(['kind' => ['Sanction absente du barème de l\'établissement. Valeurs possibles : ' . implode(', ', $scale) . '.']]);
        }
        $this->requireEnrolled($tenantId, $establishmentId, (string) $data['person_id']);
        if (isset($data['incident_id'])) {
            $incident = $this->repository->find(DisciplineRepository::INCIDENTS, $tenantId, $establishmentId, (string) $data['incident_id']);
            if ($incident === null) {
                throw HttpException::notFound('Incident introuvable.');
            }
            if ($incident['person_id'] !== $data['person_id']) {
                throw HttpException::validation(['incident_id' => ['Cet incident concerne un autre apprenant.']]);
            }
        }

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->db->transaction(function () use ($tenantId, $establishmentId, $principal, $data, $id, $now): void {
            $approvalId = $this->approvals->request($tenantId, self::KIND_APPROVAL, 'sanction', $id, (string) $data['reason'], $principal->userId);
            $this->repository->insert(DisciplineRepository::SANCTIONS, [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'incident_id' => $data['incident_id'] ?? null,
                'person_id' => $data['person_id'], 'kind' => $data['kind'], 'reason' => $data['reason'], 'status' => 'proposed',
                'approval_id' => $approvalId, 'proposed_by' => $principal->userId, 'decided_by' => null, 'decided_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->append($tenantId, $principal->userId, 'attendance.sanction.proposed', 'sanction', $id, null, ['kind' => $data['kind'], 'person_id' => $data['person_id']]);
        });

        return $this->mustFind(DisciplineRepository::SANCTIONS, $tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function decideSanction(Principal $principal, string $establishmentId, string $id, bool $approve): array
    {
        $tenantId = $principal->tenantId;
        $sanction = $this->mustFind(DisciplineRepository::SANCTIONS, $tenantId, $establishmentId, $id);
        if ($sanction['status'] !== 'proposed') {
            throw HttpException::conflict('Cette sanction a déjà été traitée.');
        }
        if ($sanction['proposed_by'] === $principal->userId) {
            throw HttpException::forbidden('La validation doit être faite par une autre personne que celle qui a proposé la sanction.');
        }

        $this->db->transaction(function () use ($tenantId, $principal, $sanction, $id, $approve): void {
            $approve
                ? $this->approvals->approve($tenantId, (string) $sanction['approval_id'], $principal->userId)
                : $this->approvals->reject($tenantId, (string) $sanction['approval_id'], $principal->userId);
            $now = $this->clock->now();
            $this->repository->update(DisciplineRepository::SANCTIONS, $tenantId, $id, [
                'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $principal->userId, 'decided_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->append($tenantId, $principal->userId, $approve ? 'attendance.sanction.approved' : 'attendance.sanction.rejected', 'sanction', $id, ['status' => 'proposed'], ['proposed_by' => $sanction['proposed_by']]);
        });

        return $this->mustFind(DisciplineRepository::SANCTIONS, $tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function mustFind(string $table, string $tenantId, string $establishmentId, string $id): array
    {
        return $this->repository->find($table, $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Élément introuvable.');
    }

    private function requireEnrolled(string $tenantId, string $establishmentId, string $personId): void
    {
        if (!in_array($personId, $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId), true)) {
            throw HttpException::notFound('Apprenant introuvable dans cet établissement.');
        }
    }
}
