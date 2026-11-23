<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Lots de génération et leurs lignes (une par titulaire : pending, done, failed ou excluded). */
final class BatchRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $this->db->insert('document_batches', $values);
    }

    /** @param array<string, scalar|null> $values */
    public function insertItem(array $values): void
    {
        $this->db->insert('document_batch_items', $values);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $id, ?string $establishmentId = null): ?array
    {
        $sql = 'SELECT * FROM document_batches WHERE id = ? AND tenant_id = ?';
        $params = [$id, $tenantId];
        if ($establishmentId !== null) {
            $sql .= ' AND establishment_id = ?';
            $params[] = $establishmentId;
        }

        return $this->db->row($sql, $params);
    }

    /** @return list<array<string, mixed>> */
    public function all(string $tenantId, string $establishmentId, int $limit, int $offset): array
    {
        return $this->db->rows(
            sprintf('SELECT * FROM document_batches WHERE tenant_id = ? AND establishment_id = ? ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset),
            [$tenantId, $establishmentId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function itemsByStatus(string $tenantId, string $batchId, string $status, int $limit): array
    {
        return $this->db->rows(
            sprintf('SELECT * FROM document_batch_items WHERE tenant_id = ? AND batch_id = ? AND status = ? ORDER BY id LIMIT %d', max(1, $limit)),
            [$tenantId, $batchId, $status],
        );
    }

    /** Marque la ligne ; ne réussit que si elle était encore en attente (deux workers ne traitent jamais la même ligne). */
    public function finishItem(string $tenantId, string $itemId, string $status, ?string $reason, ?string $documentId): int
    {
        return $this->db->exec(
            "UPDATE document_batch_items SET status = ?, reason = ?, document_id = ? WHERE id = ? AND tenant_id = ? AND status = 'pending'",
            [$status, $reason, $documentId, $itemId, $tenantId],
        );
    }

    public function progress(string $tenantId, string $id, int $processed, int $failed, string $status, ?string $finishedAt): void
    {
        $this->db->exec(
            'UPDATE document_batches SET processed = processed + ?, failed = failed + ?, status = ?, finished_at = ? WHERE id = ? AND tenant_id = ?',
            [$processed, $failed, $status, $finishedAt, $id, $tenantId],
        );
    }

    public function pendingCount(string $tenantId, string $batchId): int
    {
        return (int) ($this->db->row(
            "SELECT COUNT(*) AS n FROM document_batch_items WHERE tenant_id = ? AND batch_id = ? AND status = 'pending'",
            [$tenantId, $batchId],
        )['n'] ?? 0);
    }
}
