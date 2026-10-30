<?php

declare(strict_types=1);

namespace Tests\Support\Staff;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;

/** Doublure d'Approvals : même règle que la vraie (le demandeur ne peut pas décider, une demande ne se décide qu'une fois). */
final class FakeApprovals implements Approvals
{
    /** @var array<string, array<string, mixed>> */
    public array $requests = [];

    public function request(string $tenantId, string $kind, string $subjectType, string $subjectId, string $reason, string $requestedBy): string
    {
        $id = 'ap' . (count($this->requests) + 1);
        $this->requests[$id] = [
            'id' => $id, 'tenant_id' => $tenantId, 'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'reason' => $reason, 'requested_by' => $requestedBy, 'status' => self::PENDING,
        ];

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
        $row = $this->requests[$approvalId] ?? null;

        return $row !== null && $row['tenant_id'] === $tenantId ? $row : null;
    }

    private function decide(string $tenantId, string $approvalId, string $approverId, string $status): void
    {
        $row = $this->find($tenantId, $approvalId) ?? throw HttpException::notFound('Demande introuvable.');
        if ($row['requested_by'] === $approverId) {
            throw HttpException::forbidden('Le demandeur ne peut pas valider sa propre demande.');
        }
        if ($row['status'] !== self::PENDING) {
            throw HttpException::conflict('Demande déjà décidée.');
        }
        $this->requests[$approvalId]['status'] = $status;
    }
}
