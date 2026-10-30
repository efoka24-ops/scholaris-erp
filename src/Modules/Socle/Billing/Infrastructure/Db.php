<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Numbering\SequenceService;

/**
 * Accès SQL du module. Les noms de tables et de colonnes viennent toujours du code, jamais de la requête ;
 * les valeurs sont toujours liées. Chaque requête métier porte tenant_id.
 */
final class Db
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly SequenceService $sequences,
    ) {
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
    public function int(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? 0 : (int) $value;
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
     * @param array<string, scalar|null> $set
     * @param array<string, scalar|null> $where obligatoire, tenant_id inclus par l'appelant
     */
    public function update(string $table, array $set, array $where): int
    {
        $assign = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($set)));
        $clause = implode(' AND ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($where)));

        return $this->exec(sprintf('UPDATE %s SET %s WHERE %s', $table, $assign, $clause), [...array_values($set), ...array_values($where)]);
    }

    /**
     * Transaction (SequenceService) ; si une transaction est déjà ouverte, le travail la rejoint.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->pdo()->inTransaction()) {
            return $work();
        }

        return $this->sequences->transaction(static fn (PDO $pdo): mixed => $work());
    }

    public function isUniqueViolation(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate entry')
            || in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    /**
     * Page de résultats par curseur (identifiant croissant). $where commence par tenant_id = ? fourni par l'appelant.
     *
     * @param list<scalar|null> $params
     * @return array{0: list<array<string, mixed>>, 1: string|null} lignes et curseur suivant
     */
    public function page(string $table, string $where, array $params, ListQuery $query): array
    {
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $where .= ' AND id > ?';
            $params[] = $after;
        }
        $rows = $this->all(sprintf('SELECT * FROM %s WHERE %s ORDER BY id LIMIT %d', $table, $where, $query->perPage + 1), $params);

        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }
}
