<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use Scholaris\Core\Support\Ulid;

/**
 * File des tâches de génération en lot (statuts queued, running, done, failed).
 * Le worker est transversal aux clients : `candidate()` est la seule lecture sans filtre de client, chaque
 * écriture ultérieure est bornée par l'identifiant de la tâche et son client.
 */
final class JobRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $this->db->insert('document_jobs', $values);
    }

    /** Met le lot en file ; renvoie l'identifiant de la tâche. */
    public function enqueue(string $tenantId, string $establishmentId, string $batchId, string $at): string
    {
        $id = (string) new Ulid();
        $this->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'batch_id' => $batchId,
            'status' => 'queued', 'attempts' => 0, 'locked_at' => null, 'last_error' => null,
            'created_at' => $at, 'updated_at' => $at, 'finished_at' => null,
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $id): ?array
    {
        return $this->db->row('SELECT * FROM document_jobs WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    /** @return array<string, mixed>|null */
    public function findByBatch(string $tenantId, string $batchId): ?array
    {
        return $this->db->row('SELECT * FROM document_jobs WHERE tenant_id = ? AND batch_id = ? ORDER BY id DESC LIMIT 1', [$tenantId, $batchId]);
    }

    /**
     * Prochaine tâche à traiter : en attente, ou « en cours » mais abandonnée depuis avant `$staleBefore` (reprise).
     *
     * @return array<string, mixed>|null
     */
    public function candidate(string $staleBefore): ?array
    {
        return $this->db->row(
            "SELECT * FROM document_jobs WHERE status = 'queued' OR (status = 'running' AND locked_at < ?) ORDER BY created_at, id LIMIT 1",
            [$staleBefore],
        );
    }

    /** Prend la tâche pour ce worker ; renvoie faux si un autre worker l'a prise entre-temps. */
    public function claim(string $tenantId, string $id, string $staleBefore, string $at): bool
    {
        return $this->db->exec(
            "UPDATE document_jobs SET status = 'running', attempts = attempts + 1, locked_at = ?, updated_at = ? "
            . "WHERE id = ? AND tenant_id = ? AND (status = 'queued' OR (status = 'running' AND locked_at < ?))",
            [$at, $at, $id, $tenantId, $staleBefore],
        ) === 1;
    }

    public function heartbeat(string $tenantId, string $id, string $at): void
    {
        $this->db->exec("UPDATE document_jobs SET locked_at = ?, updated_at = ? WHERE id = ? AND tenant_id = ? AND status = 'running'", [$at, $at, $id, $tenantId]);
    }

    public function finish(string $tenantId, string $id, string $status, ?string $error, string $at): void
    {
        $this->db->exec(
            'UPDATE document_jobs SET status = ?, last_error = ?, locked_at = NULL, updated_at = ?, finished_at = ? WHERE id = ? AND tenant_id = ?',
            [$status, $error, $at, $status === 'queued' ? null : $at, $id, $tenantId],
        );
    }
}
