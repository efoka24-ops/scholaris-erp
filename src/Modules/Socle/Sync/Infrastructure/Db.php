<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Accès SQL du module. Les noms de tables et de colonnes viennent toujours du code, jamais de la requête ; les valeurs sont
 * toujours liées. Chaque requête métier porte tenant_id (la seule exception, la file lue par `sync:process`, est dans BatchRepository).
 */
final class Db
{
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
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /** @param list<scalar|null> $params */
    public function exec(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->exec(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($row)), implode(', ', array_fill(0, count($row), '?'))),
            array_values($row),
        );
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
}
