<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Demandes en ligne de documents (apprenant ou parent) : en attente, traitées ou refusées. */
final class RequestRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $this->db->insert('document_requests', $values);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->row(
            'SELECT * FROM document_requests WHERE id = ? AND tenant_id = ? AND establishment_id = ?',
            [$id, $tenantId, $establishmentId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function all(string $tenantId, string $establishmentId, ?string $status, int $limit, int $offset): array
    {
        $sql = 'SELECT * FROM document_requests WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        return $this->db->rows($sql . sprintf(' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset), $params);
    }

    public function decide(string $tenantId, string $id, string $status, string $by, string $at, ?string $documentId): int
    {
        return $this->db->exec(
            "UPDATE document_requests SET status = ?, decided_by = ?, decided_at = ?, document_id = ? WHERE id = ? AND tenant_id = ? AND status = 'pending'",
            [$status, $by, $at, $documentId, $id, $tenantId],
        );
    }
}
