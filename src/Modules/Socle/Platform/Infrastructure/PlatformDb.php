<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Infrastructure;

use InvalidArgumentException;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Accès PDO aux tables du module. Les tables d'un client (tenant_applications, platform_payments, platform_reminders)
 * sont toujours lues et modifiées avec `tenant_id = ?` explicite : les dépôts ci-dessous l'imposent.
 * Identifiants SQL validés, valeurs toujours liées.
 */
final class PlatformDb
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<scalar|null> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /** @param array<string, scalar|null> $values */
    public function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        array_map([self::class, 'assertIdentifier'], [$table, ...$columns]);
        $this->pdo()
            ->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($values));
    }

    /**
     * @param array<string, scalar|null> $values
     * @param array<string, scalar> $where au moins une condition
     */
    public function update(string $table, array $values, array $where): int
    {
        if ($where === [] || $values === []) {
            throw new InvalidArgumentException('Mise à jour sans condition ou sans valeur.');
        }
        array_map([self::class, 'assertIdentifier'], [$table, ...array_keys($values), ...array_keys($where)]);
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $cond = implode(' AND ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($where)));
        $statement = $this->pdo()->prepare('UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $cond);
        $statement->execute([...array_values($values), ...array_values($where)]);

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

    private static function assertIdentifier(string $name): void
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new InvalidArgumentException('Identifiant SQL refusé : ' . $name);
        }
    }
}
