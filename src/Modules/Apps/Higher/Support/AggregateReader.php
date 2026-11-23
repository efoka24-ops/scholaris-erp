<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Support;

use Scholaris\Core\Database\ConnectionFactory;

/**
 * Lecteur SQL des contrats d'agrégation Higher : une requête GROUP BY par tableau (jamais de boucle par étudiant). Le périmètre
 * (RG-ORG-2, RG-ORG-3) est imposé par ScopedBySchool::sqlFilter ; client et établissement sont toujours liés par l'appelant.
 */
final class AggregateReader
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly ScopedBySchool $scoped,
    ) {
    }

    /**
     * @param array<string, string> $columns clé de périmètre (school, department, program, level, group) => colonne SQL
     * @return array{0: string, 1: list<string>} clause à ajouter avec AND, paramètres positionnels
     */
    public function scope(Perimeter $perimeter, array $columns): array
    {
        return $this->scoped->sqlFilter($perimeter, $columns);
    }

    /** @param array<string, ?string> $row clé de périmètre => identifiant de la ligne */
    public function covers(Perimeter $perimeter, array $row): bool
    {
        return $this->scoped->covers($perimeter, $row);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetch(string $sql, array $params): array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }
}
