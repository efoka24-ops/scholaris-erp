<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use InvalidArgumentException;
use Scholaris\Core\Database\ConnectionFactory;

/** Accès SQL minimal : requêtes préparées uniquement, valeurs toujours liées. */
final class Db
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $params = []): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }

    /** @param list<scalar|null> $params */
    public function exec(string $sql, array $params = []): int
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /** @param array<string, scalar|null> $values */
    public function insert(string $table, array $values): void
    {
        foreach ([$table, ...array_keys($values)] as $identifier) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', (string) $identifier) !== 1) {
                throw new InvalidArgumentException('Identifiant SQL invalide : ' . $identifier);
            }
        }
        $this->exec(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($values)), implode(', ', array_fill(0, count($values), '?'))),
            array_values($values),
        );
    }
}
