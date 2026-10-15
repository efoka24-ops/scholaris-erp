<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

final class PdoApprovals implements Approvals
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly AuditLog $audit,
        private readonly string $timezone = 'Africa/Douala',
    ) {
    }

    public function request(string $tenantId, string $kind, string $subjectType, string $subjectId, string $reason, string $requestedBy): string
    {
        if (trim($reason) === '') {
            throw HttpException::validation(['reason' => ['Le motif est obligatoire.']]);
        }

        $id = (string) new Ulid();
        $this->connections->pdo()->prepare(
            'INSERT INTO approvals (id, tenant_id, kind, subject_type, subject_id, reason, requested_by, approved_by, status, created_at, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL)',
        )->execute([$id, $tenantId, $kind, $subjectType, $subjectId, $reason, $requestedBy, self::PENDING, $this->now()]);

        $this->audit->append($tenantId, $requestedBy, 'approval.requested', $subjectType, $subjectId, null, ['approval_id' => $id, 'kind' => $kind, 'reason' => $reason]);

        return $id;
    }

    public function approve(string $tenantId, string $approvalId, string $approverId): void
    {
        $this->decide($tenantId, $approvalId, $approverId, self::APPROVED);
    }

    public function reject(string $tenantId, string $approvalId, string $approverId): void
    {
        $this->decide($tenantId, $approvalId, $approverId, self::REJECTED);
    }

    public function find(string $tenantId, string $approvalId): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM approvals WHERE tenant_id = ? AND id = ?');
        $statement->execute([$tenantId, $approvalId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function decide(string $tenantId, string $approvalId, string $approverId, string $status): void
    {
        $approval = $this->find($tenantId, $approvalId) ?? throw HttpException::notFound('Demande d\'approbation introuvable.');

        if ($approval['requested_by'] === $approverId) {
            throw HttpException::forbidden('La validation doit être faite par une autre personne que le demandeur.');
        }

        $update = $this->connections->pdo()->prepare(
            'UPDATE approvals SET status = ?, approved_by = ?, decided_at = ? WHERE tenant_id = ? AND id = ? AND status = ?',
        );
        $update->execute([$status, $approverId, $this->now(), $tenantId, $approvalId, self::PENDING]);
        if ($update->rowCount() === 0) {
            throw HttpException::conflict('Cette demande a déjà été traitée.');
        }

        $this->audit->append(
            $tenantId,
            $approverId,
            $status === self::APPROVED ? 'approval.approved' : 'approval.rejected',
            (string) $approval['subject_type'],
            (string) $approval['subject_id'],
            ['status' => self::PENDING],
            ['status' => $status, 'approval_id' => $approvalId],
        );
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format(DATE_ATOM);
    }
}
