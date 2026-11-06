<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Contracts\ActivityJournal;

/** Lecture transversale du journal d'audit. Voir le contrat. Valeurs toujours liées. */
final class PdoActivityJournal implements ActivityJournal
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function search(array $filters, ?string $before, int $limit): array
    {
        $sql = 'SELECT tenant_id, seq, occurred_at, actor_id, action, object_type, object_id, after_json, ip FROM audit_log WHERE 1 = 1';
        $params = [];
        foreach (['tenant_id', 'actor_id', 'object_id'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '') {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$column];
            }
        }
        if (isset($filters['action']) && $filters['action'] !== '') {
            $sql .= " AND action LIKE ? ESCAPE '!'";
            $params[] = str_replace(['!', '_', '%'], ['!!', '!_', ''], $filters['action']) . '%';
        }
        if (isset($filters['from']) && preg_match(self::DATE, $filters['from']) === 1) {
            $sql .= ' AND occurred_at >= ?';
            $params[] = $filters['from'];
        }
        if (isset($filters['to']) && preg_match(self::DATE, $filters['to']) === 1) {
            $sql .= ' AND occurred_at <= ?';
            $params[] = $filters['to'] . '~';
        }
        $cursor = self::decode($before);
        if ($cursor !== null) {
            [$at, $tenant, $seq] = $cursor;
            $sql .= ' AND (occurred_at < ? OR (occurred_at = ? AND (tenant_id < ? OR (tenant_id = ? AND seq < ?))))';
            array_push($params, $at, $at, $tenant, $tenant, $seq);
        }
        $statement = $this->connections->pdo()->prepare($sql . sprintf(' ORDER BY occurred_at DESC, tenant_id DESC, seq DESC LIMIT %d', max(1, min($limit, 5000))));
        $statement->execute($params);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details = json_decode((string) ($row['after_json'] ?? ''), true);
            $rows[] = [
                'tenant_id' => (string) $row['tenant_id'],
                'seq' => (int) $row['seq'],
                'occurred_at' => (string) $row['occurred_at'],
                'actor_id' => $row['actor_id'] === null ? null : (string) $row['actor_id'],
                'action' => (string) $row['action'],
                'object_type' => (string) $row['object_type'],
                'object_id' => (string) $row['object_id'],
                'details' => is_array($details) ? $details : [],
                'ip' => $row['ip'] === null ? null : (string) $row['ip'],
                'cursor' => base64_encode(json_encode([(string) $row['occurred_at'], (string) $row['tenant_id'], (int) $row['seq']], JSON_THROW_ON_ERROR)),
            ];
        }

        return $rows;
    }

    public function dailyCounts(string $action, string $fromDate): array
    {
        $statement = $this->connections->pdo()->prepare('SELECT SUBSTR(occurred_at, 1, 10) AS day, COUNT(*) AS total FROM audit_log WHERE action = ? AND occurred_at >= ? GROUP BY SUBSTR(occurred_at, 1, 10)');
        $statement->execute([$action, $fromDate]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['day']] = (int) $row['total'];
        }
        ksort($out);

        return $out;
    }

    public function countsByTenant(string $since, ?string $action = null): array
    {
        $sql = 'SELECT tenant_id, COUNT(*) AS total FROM audit_log WHERE occurred_at >= ?';
        $params = [$since];
        if ($action !== null && $action !== '') {
            $sql .= " AND action LIKE ? ESCAPE '!'";
            $params[] = str_replace(['!', '_', '%'], ['!!', '!_', ''], $action) . '%';
        }
        $statement = $this->connections->pdo()->prepare($sql . ' GROUP BY tenant_id');
        $statement->execute($params);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['tenant_id']] = (int) $row['total'];
        }

        return $out;
    }

    public function count(string $action, string $since, ?string $tenantId = null, ?string $actorId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM audit_log WHERE action = ? AND occurred_at >= ?';
        $params = [$action, $since];
        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = ?';
            $params[] = $tenantId;
        }
        if ($actorId !== null) {
            $sql .= ' AND actor_id = ?';
            $params[] = $actorId;
        }
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return array{0: string, 1: string, 2: int}|null */
    private static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $data = json_decode((string) base64_decode($cursor, true), true);
        if (!is_array($data) || count($data) !== 3 || !is_string($data[0]) || !is_string($data[1]) || !is_int($data[2])) {
            return null;
        }

        return [$data[0], $data[1], $data[2]];
    }
}
