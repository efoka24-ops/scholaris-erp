<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Infrastructure;

/**
 * Tables `sync_batches` et `sync_items`. Chaque requête porte tenant_id ; la seule lecture inter-clients est la file que
 * parcourt `sync:process` (`queued()`), qui rend des lots entiers et traite chacun dans son propre client.
 */
final class BatchRepository
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const PROCESSED = 'processed';
    public const PENDING = 'pending';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByBatchId(string $tenantId, string $batchId): ?array
    {
        return $this->db->one('SELECT * FROM sync_batches WHERE tenant_id = ? AND batch_id = ?', [$tenantId, $batchId]);
    }

    /** @param array<string, scalar|null> $row */
    public function insertBatch(array $row): void
    {
        $this->db->insert('sync_batches', $row);
    }

    /** @param array<string, scalar|null> $row */
    public function insertItem(array $row): void
    {
        $this->db->insert('sync_items', $row);
    }

    /** @return list<array<string, mixed>> */
    public function items(string $tenantId, string $batchRef): array
    {
        return $this->db->all('SELECT * FROM sync_items WHERE tenant_id = ? AND batch_ref = ? ORDER BY item_no', [$tenantId, $batchRef]);
    }

    /**
     * Un autre élément du même client et du même établissement, avec le même type et le même identifiant client, déjà
     * appliqué (ou déjà rendu en doublon) : le rejeu ne doit pas être appliqué une seconde fois.
     *
     * @return array<string, mixed>|null
     */
    public function findDone(string $tenantId, string $establishmentId, string $kind, string $clientUuid, string $exceptItemId): ?array
    {
        return $this->db->one(
            "SELECT id, status, result FROM sync_items WHERE tenant_id = ? AND establishment_id = ? AND kind = ? AND client_uuid = ?
             AND status IN ('applied', 'duplicate') AND id <> ? ORDER BY id LIMIT 1",
            [$tenantId, $establishmentId, $kind, $clientUuid, $exceptItemId],
        );
    }

    /** Enregistre le résultat d'un élément et efface sa charge utile (minimisation des données de mineurs). */
    public function finishItem(string $tenantId, string $itemId, string $status, int $code, string $result, string $at): void
    {
        $this->db->exec(
            'UPDATE sync_items SET status = ?, result_code = ?, result = ?, payload = NULL, processed_at = ? WHERE tenant_id = ? AND id = ?',
            [$status, $code, $result, $at, $tenantId, $itemId],
        );
    }

    public function finishBatch(string $tenantId, string $id, string $at): void
    {
        $this->db->exec(
            "UPDATE sync_batches SET status = 'processed', processed_at = ?, locked_at = NULL WHERE tenant_id = ? AND id = ?",
            [$at, $tenantId, $id],
        );
    }

    /**
     * Lots à traiter : en file, ou « en cours » depuis avant $staleBefore (processus interrompu). Lecture volontairement
     * inter-clients : la file est celle du serveur ; chaque lot est ensuite traité dans son client.
     *
     * @return list<array<string, mixed>>
     */
    public function queued(string $staleBefore, int $limit): array
    {
        return $this->db->all(
            "SELECT * FROM sync_batches WHERE status = 'queued' OR (status = 'processing' AND locked_at < ?)
             ORDER BY received_at, id LIMIT " . max(1, $limit),
            [$staleBefore],
        );
    }

    /** Prend le lot (un seul processus gagne) : vrai si ce processus doit le traiter. */
    public function claim(string $tenantId, string $id, string $now, string $staleBefore): bool
    {
        return $this->db->exec(
            "UPDATE sync_batches SET status = 'processing', locked_at = ?
             WHERE tenant_id = ? AND id = ? AND (status = 'queued' OR (status = 'processing' AND locked_at < ?))",
            [$now, $tenantId, $id, $staleBefore],
        ) === 1;
    }
}
