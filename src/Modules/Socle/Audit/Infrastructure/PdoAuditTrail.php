<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Contracts\AuditTrail;

/** Lecture filtrée du journal d'un client, bornée par `tenant_id = ?`, valeurs toujours liées. */
final class PdoAuditTrail implements AuditTrail
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function recent(string $tenantId, array $filters, ?int $beforeSeq, int $limit): array
    {
        $sql = 'SELECT seq, occurred_at, actor_id, action, object_type, object_id, after_json, ip FROM audit_log WHERE tenant_id = ?';
        $params = [$tenantId];
        if ($beforeSeq !== null) {
            $sql .= ' AND seq < ?';
            $params[] = $beforeSeq;
        }
        if (isset($filters['action']) && $filters['action'] !== '') {
            // Préfixe littéral : « ! » échappe _ et ! (portable MySQL 8 et SQLite) ; % est retiré.
            $sql .= " AND action LIKE ? ESCAPE '!'";
            $params[] = str_replace(['!', '_', '%'], ['!!', '!_', ''], $filters['action']) . '%';
        }
        foreach (['actor_id', 'object_type', 'object_id'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '') {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$column];
            }
        }
        if (isset($filters['from'])) {
            $sql .= ' AND occurred_at >= ?';
            $params[] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $sql .= ' AND occurred_at <= ?';
            $params[] = $filters['to'] . '~';
        }
        $statement = $this->connections->pdo()->prepare($sql . sprintf(' ORDER BY seq DESC LIMIT %d', max(1, $limit)));
        $statement->execute($params);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details = json_decode((string) ($row['after_json'] ?? ''), true);
            $rows[] = [
                'seq' => (int) $row['seq'],
                'occurred_at' => (string) $row['occurred_at'],
                'actor_id' => $row['actor_id'] === null ? null : (string) $row['actor_id'],
                'action' => (string) $row['action'],
                'object_type' => (string) $row['object_type'],
                'object_id' => (string) $row['object_id'],
                'details' => is_array($details) ? $details : [],
                'ip' => $row['ip'] === null ? null : (string) $row['ip'],
            ];
        }

        return $rows;
    }
}
