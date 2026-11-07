<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;

/** Accès PDO aux demandes de clôture et aux archives d'année. Toute requête porte `tenant_id` et `establishment_id`. */
final class ClosureRepository
{
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
        $this->pdo()->prepare(
            'INSERT INTO year_closures (id, tenant_id, establishment_id, academic_year_id, next_year_id, status, approval_id, requested_by, requested_at, decided_by, closed_at, check_report, steps, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $row['id'], $row['tenant_id'], $row['establishment_id'], $row['academic_year_id'], $row['next_year_id'] ?? null, $row['status'],
            $row['approval_id'] ?? null, $row['requested_by'], $row['requested_at'], null, null, $row['check_report'], $row['steps'],
            $row['created_at'], $row['updated_at'],
        ]);
    }

    public function attachApproval(string $tenantId, string $establishmentId, string $id, string $approvalId): void
    {
        $this->pdo()->prepare('UPDATE year_closures SET approval_id = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
            ->execute([$approvalId, $tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->rows('SELECT * FROM year_closures WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id])[0] ?? null;
    }

    /** @return array<string, mixed>|null demande la plus récente de l'année */
    public function latestForYear(string $tenantId, string $establishmentId, string $yearId): ?array
    {
        return $this->rows(
            'SELECT * FROM year_closures WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? ORDER BY id DESC LIMIT 1',
            [$tenantId, $establishmentId, $yearId],
        )[0] ?? null;
    }

    public function update(string $tenantId, string $establishmentId, string $id, string $status, ?string $decidedBy, ?string $closedAt, string $steps, string $now): void
    {
        $this->pdo()->prepare(
            'UPDATE year_closures SET status = ?, decided_by = ?, closed_at = ?, steps = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
        )->execute([$status, $decidedBy, $closedAt, $steps, $now, $tenantId, $establishmentId, $id]);
    }

    public function archiveExists(string $tenantId, string $establishmentId, string $yearId): bool
    {
        return $this->rows(
            'SELECT id FROM year_archives WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ?',
            [$tenantId, $establishmentId, $yearId],
        ) !== [];
    }

    /** Archive l'état de l'année ; faux si elle l'est déjà. */
    public function insertArchive(string $id, string $tenantId, string $establishmentId, string $yearId, string $closureId, string $snapshot, string $now): bool
    {
        try {
            $this->pdo()->prepare(
                'INSERT INTO year_archives (id, tenant_id, establishment_id, academic_year_id, closure_id, snapshot, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([$id, $tenantId, $establishmentId, $yearId, $closureId, $snapshot, $now]);
        } catch (PDOException) {
            return false;
        }

        return true;
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
