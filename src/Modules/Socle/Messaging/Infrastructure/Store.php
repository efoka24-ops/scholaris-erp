<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use InvalidArgumentException;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\ListQuery;

/**
 * Accès PDO aux tables du module, toujours cloisonné par client ET établissement (aucune requête sans les deux).
 * Les noms de table et de colonne sont validés ; les valeurs sont toujours liées.
 */
final class Store
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        self::identifier($table);
        foreach (array_keys($row) as $column) {
            self::identifier((string) $column);
        }
        $this->connections->pdo()->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_keys($row)),
            implode(', ', array_fill(0, count($row), '?')),
        ))->execute(array_values($row));
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, string $tenantId, string $establishmentId, string $id): ?array
    {
        self::identifier($table);
        $statement = $this->connections->pdo()->prepare(
            sprintf('SELECT * FROM %s WHERE tenant_id = ? AND establishment_id = ? AND id = ? LIMIT 1', $table),
        );
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $table, string $tenantId, string $establishmentId, string $id, array $values): int
    {
        self::identifier($table);
        if ($values === []) {
            return 0;
        }
        foreach (array_keys($values) as $column) {
            self::identifier((string) $column);
        }
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $statement = $this->connections->pdo()->prepare(
            sprintf('UPDATE %s SET %s WHERE tenant_id = ? AND establishment_id = ? AND id = ?', $table, $sets),
        );
        $statement->execute([...array_values($values), $tenantId, $establishmentId, $id]);

        return $statement->rowCount();
    }

    public function delete(string $table, string $tenantId, string $establishmentId, string $id): int
    {
        self::identifier($table);
        $statement = $this->connections->pdo()->prepare(
            sprintf('DELETE FROM %s WHERE tenant_id = ? AND establishment_id = ? AND id = ?', $table),
        );
        $statement->execute([$tenantId, $establishmentId, $id]);

        return $statement->rowCount();
    }

    /**
     * Page par curseur, du plus récent au plus ancien (les identifiants ULID sont ordonnés dans le temps).
     *
     * @param array<string, scalar> $filters égalités (colonnes issues d'une liste blanche)
     * @return array{0: list<array<string, mixed>>, 1: string|null} lignes et curseur suivant
     */
    public function page(string $table, string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        self::identifier($table);
        $clauses = ['tenant_id = ?', 'establishment_id = ?'];
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            self::identifier($column);
            $clauses[] = $column . ' = ?';
            $params[] = $value;
        }
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $clauses[] = 'id < ?';
            $params[] = $after;
        }

        $statement = $this->connections->pdo()->prepare(sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY id DESC LIMIT %d',
            $table,
            implode(' AND ', $clauses),
            $query->perPage + 1,
        ));
        $statement->execute($params);
        $rows = array_values($statement->fetchAll(PDO::FETCH_ASSOC));

        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    private static function identifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Identifiant SQL invalide : ' . $identifier);
        }
    }
}
