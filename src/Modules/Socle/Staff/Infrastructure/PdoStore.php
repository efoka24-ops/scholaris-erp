<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Infrastructure;

use InvalidArgumentException;
use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Accès PDO aux tables du module. Règle : TOUTE lecture ou écriture reçoit explicitement le client (`tenant_id`)
 * et l'ajoute à la requête ; les tables et colonnes sont validées, les valeurs toujours liées.
 */
final class PdoStore
{
    private const TABLES = [
        'staff_members', 'staff_qualifications', 'staff_assignments', 'vacation_rates',
        'vacation_contracts', 'vacation_hours', 'vacation_statements', 'staff_moves',
    ];
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->table($table);
        foreach (array_keys($row) as $column) {
            $this->identifier((string) $column);
        }
        $this->pdo()->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_keys($row)),
            implode(', ', array_fill(0, count($row), '?')),
        ))->execute(array_values($row));
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, string $tenantId, string $id): ?array
    {
        return $this->where($table, $tenantId, ['id' => $id], 'id', 1)[0] ?? null;
    }

    /**
     * @param array<string, scalar|null> $conditions égalités (null = IS NULL)
     * @return list<array<string, mixed>>
     */
    public function where(string $table, string $tenantId, array $conditions = [], string $orderBy = 'id', int $limit = 1000): array
    {
        $this->table($table);
        $this->identifier($orderBy);
        [$clause, $params] = $this->clause($tenantId, $conditions);
        $statement = $this->pdo()->prepare(sprintf('SELECT * FROM %s WHERE %s ORDER BY %s LIMIT %d', $table, $clause, $orderBy, max(1, $limit)));
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /** @param array<string, scalar|null> $conditions */
    public function count(string $table, string $tenantId, array $conditions = []): int
    {
        $this->table($table);
        [$clause, $params] = $this->clause($tenantId, $conditions);
        $statement = $this->pdo()->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $clause));
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $table, string $tenantId, string $id, array $values): int
    {
        $this->table($table);
        unset($values['id'], $values['tenant_id']);
        if ($values === []) {
            return 0;
        }
        foreach (array_keys($values) as $column) {
            $this->identifier((string) $column);
        }
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $statement = $this->pdo()->prepare(sprintf('UPDATE %s SET %s WHERE tenant_id = ? AND id = ?', $table, $sets));
        $statement->execute([...array_values($values), $tenantId, $id]);

        return $statement->rowCount();
    }

    /** @param array<string, scalar|null> $conditions non vide */
    public function delete(string $table, string $tenantId, array $conditions): int
    {
        $this->table($table);
        if ($conditions === []) {
            throw new InvalidArgumentException('DELETE sans condition refusé.');
        }
        [$clause, $params] = $this->clause($tenantId, $conditions);
        $statement = $this->pdo()->prepare(sprintf('DELETE FROM %s WHERE %s', $table, $clause));
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * Requête libre : l'appelant DOIT y mettre `tenant_id = ?`.
     *
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (PDOException) {
                // transaction déjà terminée
            }
            throw $e;
        }
    }

    /**
     * @param array<string, scalar|null> $conditions
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function clause(string $tenantId, array $conditions): array
    {
        $clauses = ['tenant_id = ?'];
        $params = [$tenantId];
        foreach ($conditions as $column => $value) {
            $this->identifier((string) $column);
            if ($value === null) {
                $clauses[] = $column . ' IS NULL';
                continue;
            }
            $clauses[] = $column . ' = ?';
            $params[] = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function table(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException('Table inconnue : ' . $table);
        }
    }

    private function identifier(string $name): void
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new InvalidArgumentException('Identifiant SQL invalide : ' . $name);
        }
    }
}
