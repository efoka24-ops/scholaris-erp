<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Infrastructure;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;

/**
 * Accès PDO aux tables `hi_internship*` et `hi_defense*` : `tenant_id = ? AND establishment_id = ?` est ajouté à chaque requête,
 * identifiants SQL validés (préfixe hi_ obligatoire), valeurs liées. Un identifiant d'un autre client est simplement introuvable.
 */
final class InternshipStore
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param array<string, scalar|null> $where égalités
     * @param list<scalar|null> $extraParams paramètres positionnels de $extra (filtre de périmètre)
     * @return list<array<string, mixed>>
     */
    public function select(string $table, string $tenantId, string $establishmentId, array $where = [], string $orderBy = 'id', int $limit = 1000, string $extra = '1 = 1', array $extraParams = [], int $offset = 0): array
    {
        self::table($table);
        foreach (explode(',', $orderBy) as $part) {
            self::identifier(trim((string) preg_replace('/\s+(ASC|DESC)$/i', '', trim($part))));
        }
        [$clause, $params] = self::conditions($tenantId, $establishmentId, $where);
        $statement = $this->connections->pdo()->prepare(sprintf(
            'SELECT * FROM %s WHERE %s AND (%s) ORDER BY %s LIMIT %d OFFSET %d',
            $table,
            $clause,
            $extra,
            $orderBy,
            max(1, $limit),
            max(0, $offset),
        ));
        $statement->execute([...$params, ...$extraParams]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->select($table, $tenantId, $establishmentId, ['id' => $id], 'id', 1)[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function findOrFail(string $table, string $tenantId, string $establishmentId, string $id, string $message = 'Ressource introuvable.'): array
    {
        return $this->find($table, $tenantId, $establishmentId, $id) ?? throw HttpException::notFound($message);
    }

    /** @param array<string, scalar|null> $values */
    public function insert(string $table, string $tenantId, string $establishmentId, array $values): void
    {
        self::table($table);
        $values = ['tenant_id' => $tenantId, 'establishment_id' => $establishmentId] + $values;
        foreach (array_keys($values) as $column) {
            self::identifier((string) $column);
        }
        $statement = $this->connections->pdo()->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_keys($values)),
            implode(', ', array_fill(0, count($values), '?')),
        ));
        try {
            $statement->execute(array_values($values));
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || (string) $e->getCode() === '23000') {
                throw HttpException::conflict('Un enregistrement identique existe déjà dans cet établissement.');
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
            return 0;
        }
        foreach (array_keys($values) as $column) {
            self::identifier((string) $column);
        }
        [$clause, $params] = self::conditions($tenantId, $establishmentId, $where);
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $statement = $this->connections->pdo()->prepare(sprintf('UPDATE %s SET %s WHERE %s', $table, $sets, $clause));
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
        $statement = $this->connections->pdo()->prepare(sprintf('DELETE FROM %s WHERE %s', $table, $clause));
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
        if (!str_starts_with($table, 'hi_internship') && !str_starts_with($table, 'hi_defense')) {
            throw new \InvalidArgumentException('Table hors du module des stages : ' . $table);
        }
    }

    private static function identifier(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new \InvalidArgumentException('Identifiant SQL invalide : ' . $identifier);
        }
    }
}
