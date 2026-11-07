<?php

declare(strict_types=1);

namespace Scholaris\Core\Tenancy;

use InvalidArgumentException;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Accès à une table métier cloisonnée par client : `tenant_id = ?` est ajouté à TOUTE requête
 * et refusé sans contexte (défaut = refus). Les identifiants SQL (table, colonnes) sont validés,
 * les valeurs sont toujours liées. UPDATE et DELETE sans condition sont impossibles.
 *
 * Quand la table porte aussi `establishment_id`, `forEstablishment()` ajoute ce filtre.
 */
final class TenantTable
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    private bool $byEstablishment = false;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly TenantContext $context,
        private readonly string $table,
    ) {
        self::assertIdentifier($table);
    }

    public function forEstablishment(): self
    {
        $clone = clone $this;
        $clone->byEstablishment = true;

        return $clone;
    }

    /**
     * @param array<string, scalar|null> $where égalités
     * @return list<array<string, mixed>>
     */
    public function select(array $where = [], string $orderBy = 'id', int $limit = 200): array
    {
        self::assertIdentifier($orderBy);
        [$clause, $params] = $this->conditions($where);
        $statement = $this->pdo()->prepare(
            sprintf('SELECT * FROM %s WHERE %s ORDER BY %s LIMIT %d', $this->table, $clause, $orderBy, max(1, $limit)),
        );
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $rows = $this->select(['id' => $id], 'id', 1);

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function findOrFail(string $id): array
    {
        return $this->find($id) ?? throw new \Scholaris\Core\Http\HttpException(
            404,
            'Introuvable',
            'Ressource introuvable.',
            'https://api.scholaris.cm/errors/not-found',
        );
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $values['tenant_id'] = $this->context->tenantId();
        if ($this->byEstablishment) {
            $values['establishment_id'] = $this->context->establishmentId();
        }
        foreach (array_keys($values) as $column) {
            self::assertIdentifier((string) $column);
        }

        $statement = $this->pdo()->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_keys($values)),
            implode(', ', array_fill(0, count($values), '?')),
        ));
        $statement->execute(array_values($values));
    }

    /**
     * @param array<string, scalar|null> $values
     * @param array<string, scalar|null> $where obligatoire
     */
    public function update(array $values, array $where): int
    {
        if ($where === []) {
            throw new InvalidArgumentException('UPDATE sans condition refusé.');
        }
        unset($values['tenant_id'], $values['establishment_id']);
        if ($values === []) {
            return 0;
        }
        foreach (array_keys($values) as $column) {
            self::assertIdentifier((string) $column);
        }

        [$clause, $params] = $this->conditions($where);
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $statement = $this->pdo()->prepare(sprintf('UPDATE %s SET %s WHERE %s', $this->table, $sets, $clause));
        $statement->execute([...array_values($values), ...$params]);

        return $statement->rowCount();
    }

    /** @param array<string, scalar|null> $where obligatoire */
    public function delete(array $where): int
    {
        if ($where === []) {
            throw new InvalidArgumentException('DELETE sans condition refusé.');
        }
        [$clause, $params] = $this->conditions($where);
        $statement = $this->pdo()->prepare(sprintf('DELETE FROM %s WHERE %s', $this->table, $clause));
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * @param array<string, scalar|null> $where
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function conditions(array $where): array
    {
        $clauses = ['tenant_id = ?'];
        $params = [$this->context->tenantId()];

        if ($this->byEstablishment) {
            $clauses[] = 'establishment_id = ?';
            $params[] = $this->context->establishmentId();
        }

        foreach ($where as $column => $value) {
            self::assertIdentifier((string) $column);
            if ($value === null) {
                $clauses[] = $column . ' IS NULL';
                continue;
            }
            $clauses[] = $column . ' = ?';
            $params[] = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new InvalidArgumentException('Identifiant SQL invalide : ' . $identifier);
        }
    }
}
