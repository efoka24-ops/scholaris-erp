<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Socle des dépôts PDO d'Identity. Règle : toute méthode qui lit ou écrit une donnée d'un client reçoit
 * explicitement le `tenant_id` et l'ajoute à la requête. Les seules recherches sans client sont celles
 * qui partent d'une clé secrète ou d'un identifiant de connexion (jeton, identifiant saisi, ticket).
 */
abstract class PdoRepository
{
    public function __construct(protected readonly ConnectionFactory $connections)
    {
    }

    protected function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    protected function one(string $sql, array $params): ?array
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
    protected function rows(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /** @param list<scalar|null> $params */
    protected function run(string $sql, array $params): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    protected static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
