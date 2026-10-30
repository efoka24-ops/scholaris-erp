<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Infrastructure;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;

/**
 * Accès PDO aux tables des vacations (hi_vacation_*), client et établissement TOUJOURS explicites : `tenant_id = ? AND
 * establishment_id = ?` est ajouté à chaque requête. Identifiants SQL validés, valeurs liées.
 */
final class VacationStore
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param array<string, scalar|null> $where égalités
     * @param list<scalar|null> $extraParams
     * @return list<array<string, mixed>>
     */
    public function select(string $table, string $tenantId, string $establishmentId, array $where = [], string $orderBy = 'id', int $limit = 1000, string $extra = '1 = 1', array $extraParams = [], int $offset = 0): array
    {
        self::table($table);
        foreach (explode(',', $orderBy) as $part) {
            self::identifier(trim((string) preg_replace('/\s+(ASC|DESC)$/i', '', trim($part))));
        }
        [$clause, $params] = self::conditions($tenantId, $establishmentId, $where);
        $statement = $this->pdo()->prepare(sprintf('SELECT * FROM %s WHERE %s AND (%s) ORDER BY %s LIMIT %d OFFSET %d', $table, $clause, $extra, $orderBy, max(1, $limit), max(0, $offset)));
        $statement->execute([...$params, ...$extraParams]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->select($table, $tenantId, $establishmentId, ['id' => $id], 'id', 1)[0] ?? null;
    }

    /** @param array<string, scalar|null> $values */
    public function insert(string $table, string $tenantId, string $establishmentId, array $values): void
    {
        self::table($table);
        $values = ['tenant_id' => $tenantId, 'establishment_id' => $establishmentId] + $values;
        foreach (array_keys($values) as $column) {
            self::identifier((string) $column);
        }
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($values)), implode(', ', array_fill(0, count($values), '?')));
        try {
            $this->pdo()->prepare($sql)->execute(array_values($values));
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw HttpException::conflict('Un état existe déjà pour cet enseignant, ce département et ce mois.');
            }
            throw $e;
        }
    }

    /**
     * @param array<string, scalar|null> $values
     * @param array<string, scalar|null> $where obligatoire
     */
    public function update(string $table, string $tenantId, string $establishmentId, array $values, array $where): int
    {
        self::table($table);
        unset($values['tenant_id'], $values['establishment_id'], $values['id']);
        if ($where === [] || $values === []) {
            throw new \InvalidArgumentException('Mise à jour sans valeur ou sans condition refusée.');
        }
        foreach (array_keys($values) as $column) {
            self::identifier((string) $column);
        }
        [$clause, $params] = self::conditions($tenantId, $establishmentId, $where);
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $statement = $this->pdo()->prepare(sprintf('UPDATE %s SET %s WHERE %s', $table, $sets, $clause));
        $statement->execute([...array_values($values), ...$params]);

        return $statement->rowCount();
    }

    /** @param array<string, scalar|null> $where obligatoire */
    public function delete(string $table, string $tenantId, string $establishmentId, array $where): int
    {
        self::table($table);
        if ($where === []) {
            throw new \InvalidArgumentException('Suppression sans condition refusée.');
        }
        [$clause, $params] = self::conditions($tenantId, $establishmentId, $where);
        $statement = $this->pdo()->prepare(sprintf('DELETE FROM %s WHERE %s', $table, $clause));
        $statement->execute($params);

        return $statement->rowCount();
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
            $pdo->rollBack();
            throw $e;
        }
    }

    private function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /**
     * @param array<string, scalar|null> $where
     * @return array{0: string, 1: list<scalar|null>}
     */
    private static function conditions(string $tenantId, string $establishmentId, array $where): array
    {
        $clauses = ['tenant_id = ?', 'establishment_id = ?'];
        $params = [$tenantId, $establishmentId];
        foreach ($where as $column => $value) {
            self::identifier((string) $column);
            if ($value === null) {
                $clauses[] = $column . ' IS NULL';
                continue;
            }
            $clauses[] = $column . ' = ?';
            $params[] = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private static function table(string $table): void
    {
        self::identifier($table);
        if (!str_starts_with($table, 'hi_vacation_')) {
            throw new \InvalidArgumentException('Table hors du module des vacations : ' . $table);
        }
    }

    private static function identifier(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new \InvalidArgumentException('Identifiant SQL invalide : ' . $identifier);
        }
    }
}
