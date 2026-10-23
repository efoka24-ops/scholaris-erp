<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;

/** Accès SQL minimal : requêtes préparées uniquement, valeurs toujours liées. */
final class PrivacyDb
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
}
