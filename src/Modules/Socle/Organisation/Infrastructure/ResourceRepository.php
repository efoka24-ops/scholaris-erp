<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Tenancy\TenantTable;

/**
 * Dépôt d'une table cloisonnée : tout passe par TenantTable (filtre tenant, et établissement si la table en porte un).
 * La pagination par curseur et le tri sont appliqués sur des ensembles de référence de taille modeste.
 */
final class ResourceRepository
{
    private const HARD_LIMIT = 10000;

    public function __construct(private readonly TenantTable $table)
    {
    }

    /**
     * @param array<string, scalar|null> $where
     * @param (callable(array<string, mixed>): bool)|null $keep filtre complémentaire (droits par ligne), appliqué avant la pagination
     * @return array{0: list<array<string, mixed>>, 1: ?string} lignes de la page et curseur suivant
     */
    public function page(ListQuery $query, array $where = [], ?callable $keep = null): array
    {
        $rows = $this->table->select($query->filters + $where, 'id', self::HARD_LIMIT);
        if ($keep !== null) {
            $rows = array_values(array_filter($rows, $keep));
        }

        if ($query->sorts !== []) {
            usort($rows, static function (array $a, array $b) use ($query): int {
                foreach ($query->sorts as [$field, $direction]) {
                    $comparison = $a[$field] <=> $b[$field];
                    if ($comparison !== 0) {
                        return $direction === 'desc' ? -$comparison : $comparison;
                    }
                }

                return strcmp((string) $a['id'], (string) $b['id']);
            });
        }

        $offset = 0;
        if ($query->cursor !== null) {
            $lastId = ListQuery::decodeCursor($query->cursor);
            $position = $lastId === null ? false : array_search($lastId, array_column($rows, 'id'), true);
            if ($position === false) {
                throw HttpException::badRequest('Curseur invalide ou périmé.');
            }
            $offset = $position + 1;
        }

        $slice = array_slice($rows, $offset, $query->perPage);
        $next = $offset + $query->perPage < count($rows) && $slice !== []
            ? ListQuery::encodeCursor((string) $slice[array_key_last($slice)]['id'])
            : null;

        return [$slice, $next];
    }

    /**
     * @param array<string, scalar|null> $where
     * @return list<array<string, mixed>>
     */
    public function all(array $where = [], string $orderBy = 'id'): array
    {
        return $this->table->select($where, $orderBy, self::HARD_LIMIT);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->table->find($id);
    }

    /** @return array<string, mixed> */
    public function findOrFail(string $id): array
    {
        return $this->table->findOrFail($id);
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $this->table->insert($values);
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $id, array $values): int
    {
        return $this->table->update($values, ['id' => $id]);
    }

    public function delete(string $id): int
    {
        return $this->table->delete(['id' => $id]);
    }
}
