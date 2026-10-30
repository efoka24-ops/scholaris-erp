<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;

/** Accès PDO aux rapports planifiés. Toute lecture ou écriture d'un rapport porte `tenant_id` et `establishment_id`. */
final class ScheduleRepository
{
    private const COLUMNS = [
        'id', 'tenant_id', 'establishment_id', 'name', 'report_type', 'frequency', 'run_day', 'recipient_ids', 'status',
        'next_run_on', 'last_run_at', 'last_run_status', 'created_by', 'created_at', 'updated_at',
    ];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    private function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @param array<string, scalar|null> $row */
    public function insert(array $row): void
    {
        $this->pdo()->prepare(sprintf(
            'INSERT INTO report_schedules (%s) VALUES (%s)',
            implode(', ', self::COLUMNS),
            implode(', ', array_fill(0, count(self::COLUMNS), '?')),
        ))->execute(array_map(static fn (string $column) => $row[$column] ?? null, self::COLUMNS));
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->rows(
            'SELECT * FROM report_schedules WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
            [$tenantId, $establishmentId, $id],
        )[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function forEstablishment(string $tenantId, string $establishmentId, ?string $afterId, int $limit): array
    {
        return $this->rows(
            'SELECT * FROM report_schedules WHERE tenant_id = ? AND establishment_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit),
            [$tenantId, $establishmentId, $afterId ?? ''],
        );
    }

    /**
     * Rapports échus, tous clients confondus (réservé à la commande console), éventuellement limités à un client.
     *
     * @return list<array<string, mixed>>
     */
    public function due(string $today, ?string $tenantId): array
    {
        if ($tenantId === null) {
            return $this->rows('SELECT * FROM report_schedules WHERE status = ? AND next_run_on <= ? ORDER BY tenant_id, id', ['active', $today]);
        }

        return $this->rows('SELECT * FROM report_schedules WHERE status = ? AND next_run_on <= ? AND tenant_id = ? ORDER BY id', ['active', $today, $tenantId]);
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $tenantId, string $establishmentId, string $id, array $values): void
    {
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (!in_array($column, self::COLUMNS, true) || in_array($column, ['id', 'tenant_id', 'establishment_id'], true)) {
                continue;
            }
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        if ($sets === []) {
            return;
        }
        array_push($params, $tenantId, $establishmentId, $id);
        $this->pdo()->prepare('UPDATE report_schedules SET ' . implode(', ', $sets) . ' WHERE tenant_id = ? AND establishment_id = ? AND id = ?')->execute($params);
    }

    public function delete(string $tenantId, string $establishmentId, string $id): void
    {
        $this->pdo()->prepare('DELETE FROM report_schedules WHERE tenant_id = ? AND establishment_id = ? AND id = ?')->execute([$tenantId, $establishmentId, $id]);
    }

    /** Réserve une exécution : faux si cette clé a déjà été jouée (idempotence). */
    public function claimRun(string $id, string $tenantId, string $establishmentId, string $scheduleId, string $runKey, string $trigger, string $now): bool
    {
        try {
            $this->pdo()->prepare(
                'INSERT INTO report_runs (id, tenant_id, establishment_id, schedule_id, run_key, trigger_kind, status, sent_count, failed_count, summary, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, ?)',
            )->execute([$id, $tenantId, $establishmentId, $scheduleId, $runKey, $trigger, 'running', $now]);
        } catch (PDOException) {
            return false;
        }

        return true;
    }

    public function finishRun(string $tenantId, string $id, string $status, int $sent, int $failed, ?string $summary): void
    {
        $this->pdo()->prepare('UPDATE report_runs SET status = ?, sent_count = ?, failed_count = ?, summary = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$status, $sent, $failed, $summary, $tenantId, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function runs(string $tenantId, string $establishmentId, string $scheduleId, int $limit): array
    {
        return $this->rows(
            'SELECT * FROM report_runs WHERE tenant_id = ? AND establishment_id = ? AND schedule_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$tenantId, $establishmentId, $scheduleId],
        );
    }

    /**
     * @param list<scalar> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }
}
