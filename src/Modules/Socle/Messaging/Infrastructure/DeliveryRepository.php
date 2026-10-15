<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use InvalidArgumentException;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/** Livraisons : création, file à traiter (toutes entités, pour un worker), mise à jour et statistiques par message. */
final class DeliveryRepository
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * Livraisons à traiter dont l'heure est venue.
     *
     * @return list<array<string, mixed>>
     */
    public function due(string $now, int $limit, ?string $tenantId = null, ?string $messageId = null): array
    {
        $clauses = ["status IN ('pending', 'queued')", '(not_before IS NULL OR not_before <= ?)'];
        $params = [$now];
        if ($tenantId !== null) {
            $clauses[] = 'tenant_id = ?';
            $params[] = $tenantId;
        }
        if ($messageId !== null) {
            if ($tenantId === null) {
                throw new InvalidArgumentException('Un message se désigne toujours avec son client.');
            }
            $clauses[] = 'message_id = ?';
            $params[] = $messageId;
        }
        $statement = $this->connections->pdo()->prepare(
            sprintf('SELECT * FROM message_deliveries WHERE %s ORDER BY id LIMIT %d', implode(' AND ', $clauses), max(1, $limit)),
        );
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Livraisons en échec qui peuvent encore être reprises, les plus anciennes d'abord (tous clients : usage worker).
     *
     * @return list<array<string, mixed>>
     */
    public function failedBelow(int $maxAttempts, int $limit): array
    {
        $statement = $this->connections->pdo()->prepare(
            sprintf("SELECT * FROM message_deliveries WHERE status = 'failed' AND attempts < ? ORDER BY updated_at, id LIMIT %d", max(1, $limit)),
        );
        $statement->execute([$maxAttempts]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Remet en file une livraison en échec, une seule fois même si deux workers tournent (garde sur le statut
     * et sur le nombre de tentatives).
     */
    public function claimRetry(string $tenantId, string $id, int $attempts, string $now): bool
    {
        $statement = $this->connections->pdo()->prepare(
            "UPDATE message_deliveries SET status = 'queued', reason = 'retry', not_before = ?, updated_at = ? "
            . "WHERE tenant_id = ? AND id = ? AND status = 'failed' AND attempts = ?",
        );
        $statement->execute([$now, $now, $tenantId, $id, $attempts]);

        return $statement->rowCount() === 1;
    }

    /** Marque comme abandonnées les livraisons en échec qui ont épuisé leurs tentatives. @return int nombre de livraisons */
    public function abandonExhausted(int $maxAttempts, string $reason, string $now): int
    {
        $statement = $this->connections->pdo()->prepare(
            "UPDATE message_deliveries SET reason = ?, updated_at = ? WHERE status = 'failed' AND attempts >= ? AND (reason IS NULL OR reason <> ?)",
        );
        $statement->execute([$reason, $now, $maxAttempts, $reason]);

        return $statement->rowCount();
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $tenantId, string $id, array $values): void
    {
        $allowed = ['status', 'reason', 'provider_reference', 'not_before', 'attempts', 'sent_at', 'delivered_at', 'updated_at'];
        $sets = [];
        foreach (array_keys($values) as $column) {
            if (!in_array($column, $allowed, true)) {
                throw new InvalidArgumentException('Colonne non modifiable : ' . $column);
            }
            $sets[] = $column . ' = ?';
        }
        $this->connections->pdo()
            ->prepare('UPDATE message_deliveries SET ' . implode(', ', $sets) . ' WHERE tenant_id = ? AND id = ?')
            ->execute([...array_values($values), $tenantId, $id]);
    }

    /** Accusé de réception du fournisseur. @return int nombre de livraisons marquées */
    public function markDelivered(string $tenantId, string $providerReference, string $at): int
    {
        $statement = $this->connections->pdo()->prepare(
            "UPDATE message_deliveries SET status = 'delivered', delivered_at = ?, updated_at = ? "
            . "WHERE tenant_id = ? AND provider_reference = ? AND status = 'sent'",
        );
        $statement->execute([$at, $at, $tenantId, $providerReference]);

        return $statement->rowCount();
    }

    /** @return array<string, int> statut => nombre */
    public function countsByStatus(string $tenantId, string $establishmentId, string $messageId): array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT status, COUNT(*) AS total FROM message_deliveries WHERE tenant_id = ? AND establishment_id = ? AND message_id = ? GROUP BY status',
        );
        $statement->execute([$tenantId, $establishmentId, $messageId]);
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
