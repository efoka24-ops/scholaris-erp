<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Infrastructure\Db;
use Scholaris\Modules\Socle\Attendance\Infrastructure\JustificationRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Justificatifs d'absence : déposés par la vie scolaire, l'apprenant ou son garant (référence de document facultative,
 * aucun fichier stocké ici), puis validés ou refusés par la vie scolaire. Une absence justifiée ne compte plus dans le non justifié.
 */
final class JustificationService
{
    public function __construct(
        private readonly Db $db,
        private readonly JustificationRepository $repository,
        private readonly EnrollmentDirectory $enrollments,
        private readonly AttendanceAccess $access,
        private readonly AuditLog $audit,
        private readonly AttendanceClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data person_id, absence_from, absence_to, reason, document_ref
     * @return array<string, mixed>
     */
    public function submit(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $personId = (string) $data['person_id'];
        if (!in_array($personId, $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId), true)) {
            throw HttpException::notFound('Apprenant introuvable dans cet établissement.');
        }
        if (!$this->access->can($principal, $establishmentId, AttendanceAccess::JUSTIFY) && !$this->access->isSelfOrGuardian($principal, $personId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de déposer un justificatif pour cet apprenant.');
        }
        if ($data['absence_to'] < $data['absence_from']) {
            throw HttpException::validation(['absence_to' => ['La fin de l\'absence ne peut pas précéder son début.']]);
        }

        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->repository->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'person_id' => $personId,
            'absence_from' => $data['absence_from'], 'absence_to' => $data['absence_to'], 'reason' => $data['reason'],
            'document_ref' => $data['document_ref'] ?? null, 'status' => 'pending', 'submitted_by' => $principal->userId,
            'decided_by' => null, 'decided_at' => null, 'decision_note' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit->append($tenantId, $principal->userId, 'attendance.justification.submitted', 'absence_justification', $id, null, ['person_id' => $personId]);

        return $this->mustFind($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> le justificatif décidé, avec le nombre d'absences justifiées */
    public function decide(Principal $principal, string $establishmentId, string $id, bool $accept, ?string $note): array
    {
        $tenantId = $principal->tenantId;
        $justification = $this->mustFind($tenantId, $establishmentId, $id);
        if ($justification['status'] !== 'pending') {
            throw HttpException::conflict('Ce justificatif a déjà été traité.');
        }

        $now = $this->clock->now();
        $justified = $this->db->transaction(function () use ($tenantId, $establishmentId, $principal, $justification, $id, $accept, $note, $now): int {
            $this->repository->update($tenantId, $id, [
                'status' => $accept ? 'accepted' : 'rejected', 'decided_by' => $principal->userId, 'decided_at' => $now,
                'decision_note' => $note, 'updated_at' => $now,
            ]);
            $count = $accept
                ? $this->repository->justifyAbsences($tenantId, $establishmentId, (string) $justification['person_id'], (string) $justification['absence_from'], (string) $justification['absence_to'], $id, $now)
                : 0;
            $this->audit->append($tenantId, $principal->userId, $accept ? 'attendance.justification.accepted' : 'attendance.justification.rejected', 'absence_justification', $id, ['status' => 'pending'], ['absences_justified' => $count]);

            return $count;
        });

        return $this->mustFind($tenantId, $establishmentId, $id) + ['absences_justified' => $justified];
    }

    /** @return array<string, mixed> */
    public function mustFind(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->repository->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Justificatif introuvable.');
    }
}
