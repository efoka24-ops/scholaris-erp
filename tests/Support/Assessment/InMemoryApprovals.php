<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;

/** Doublure d'Approvals : celui qui demande ne peut jamais valider. */
final class InMemoryApprovals implements Approvals
{
    /** @var array<string, array<string, mixed>> */
    public array $items = [];

    public function request(string $tenantId, string $kind, string $subjectType, string $subjectId, string $reason, string $requestedBy): string
    {
        $id = 'apr-' . (count($this->items) + 1);
        $this->items[$id] = ['id' => $id, 'tenant_id' => $tenantId, 'kind' => $kind, 'subject_id' => $subjectId, 'reason' => $reason, 'requested_by' => $requestedBy, 'status' => self::PENDING, 'approved_by' => null];

        return $id;
    }

    public function approve(string $tenantId, string $approvalId, string $approverId): void
    {
        $item = $this->pending($tenantId, $approvalId);
        if ($item['requested_by'] === $approverId) {
            throw HttpException::forbidden('Le demandeur ne peut pas valider.');
        }
        $this->items[$approvalId]['status'] = self::APPROVED;
        $this->items[$approvalId]['approved_by'] = $approverId;
    }

    public function reject(string $tenantId, string $approvalId, string $approverId): void
    {
        $this->pending($tenantId, $approvalId);
        $this->items[$approvalId]['status'] = self::REJECTED;
    }

    public function find(string $tenantId, string $approvalId): ?array
    {
        $item = $this->items[$approvalId] ?? null;

        return $item !== null && $item['tenant_id'] === $tenantId ? $item : null;
    }

    /** @return array<string, mixed> */
    private function pending(string $tenantId, string $approvalId): array
    {
        $item = $this->find($tenantId, $approvalId) ?? throw HttpException::notFound('Demande introuvable.');
        if ($item['status'] !== self::PENDING) {
            throw HttpException::conflict('Demande déjà traitée.');
        }

        return $item;
    }
}
