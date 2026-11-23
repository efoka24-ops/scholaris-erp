<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Lots d'import des acteurs et leurs lignes normalisées. Toute requête porte `tenant_id` ET `establishment_id` : un lot d'un autre
 * client ou d'un autre établissement n'est jamais lisible (le service répond alors 404). Valeurs toujours liées.
 */
final class BatchStore
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->connections->pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, scalar|null> $batch */
    public function insertBatch(array $batch): void
    {
        $this->insert('import_people_batches', $batch);
    }

    /** @param array<string, scalar|null> $line */
    public function insertLine(array $line): void
    {
        $this->insert('import_people_lines', $line);
    }

    /** @return array<string, mixed>|null */
    public function batch(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM import_people_batches WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<string>|null $statuses
     * @return list<array<string, mixed>>
     */
    public function lines(string $tenantId, string $establishmentId, string $batchId, ?array $statuses = null): array
    {
        $sql = 'SELECT * FROM import_people_lines WHERE tenant_id = ? AND establishment_id = ? AND batch_id = ?';
        $params = [$tenantId, $establishmentId, $batchId];
        if ($statuses !== null) {
            $sql .= ' AND line_status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        $statement = $this->connections->pdo()->prepare($sql . ' ORDER BY line_no');
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Prend le lot pour l'application : réussit pour UN seul appelant, sur un lot analysé ou dont une application précédente
     * s'est interrompue (verrou plus ancien que `$staleBefore`). C'est ce qui fait qu'un lot ne s'applique qu'une fois.
     */
    public function claim(string $tenantId, string $establishmentId, string $id, string $now, string $staleBefore, string $actorId, string $mode, bool $sendCodes): bool
    {
        $statement = $this->connections->pdo()->prepare(
            "UPDATE import_people_batches SET batch_status = 'applying', locked_at = ?, committed_by = ?, credentials_mode = ?, send_codes = ? "
            . "WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND (batch_status = 'previewed' OR (batch_status = 'applying' AND locked_at < ?))",
        );
        $statement->execute([$now, $actorId, $mode, $sendCodes ? 1 : 0, $tenantId, $establishmentId, $id, $staleBefore]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, scalar|null> $values */
    public function updateLine(string $tenantId, string $lineId, array $values): void
    {
        $columns = array_keys($values);
        $statement = $this->connections->pdo()->prepare(
            'UPDATE import_people_lines SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns)) . ' WHERE tenant_id = ? AND id = ?',
        );
        $statement->execute([...array_values($values), $tenantId, $lineId]);
    }

    /** @param array<string, scalar|null> $values */
    public function updateBatch(string $tenantId, string $establishmentId, string $id, array $values): void
    {
        $columns = array_keys($values);
        $statement = $this->connections->pdo()->prepare(
            'UPDATE import_people_batches SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns)) . ' WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
        );
        $statement->execute([...array_values($values), $tenantId, $establishmentId, $id]);
    }

    /** Efface les données personnelles des lignes appliquées : il n'en reste que l'état, le rôle et les identifiants créés. */
    public function scrubApplied(string $tenantId, string $establishmentId, string $batchId): void
    {
        $this->connections->pdo()->prepare(
            'UPDATE import_people_lines SET last_name = NULL, first_name = NULL, email = NULL, phone = NULL, note = NULL '
            . "WHERE tenant_id = ? AND establishment_id = ? AND batch_id = ? AND line_status = 'applied'",
        )->execute([$tenantId, $establishmentId, $batchId]);
    }

    /** Supprime les lots expirés de l'établissement (et leurs lignes) : les données de fichier ne durent pas. */
    public function purgeExpired(string $tenantId, string $establishmentId, string $now): void
    {
        $pdo = $this->connections->pdo();
        $ids = $pdo->prepare('SELECT id FROM import_people_batches WHERE tenant_id = ? AND establishment_id = ? AND expires_at < ?');
        $ids->execute([$tenantId, $establishmentId, $now]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->delete($tenantId, $establishmentId, (string) $id);
        }
    }

    public function delete(string $tenantId, string $establishmentId, string $id): void
    {
        $pdo = $this->connections->pdo();
        $pdo->prepare('DELETE FROM import_people_lines WHERE tenant_id = ? AND establishment_id = ? AND batch_id = ?')->execute([$tenantId, $establishmentId, $id]);
        $pdo->prepare('DELETE FROM import_people_batches WHERE tenant_id = ? AND establishment_id = ? AND id = ?')->execute([$tenantId, $establishmentId, $id]);
    }

    /** @param array<string, scalar|null> $values */
    private function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $this->connections->pdo()
            ->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($values));
    }
}
