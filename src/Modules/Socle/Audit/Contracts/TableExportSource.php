<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

use InvalidArgumentException;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Base des sources d'export des modules : chaque module déclare, table par table, la LISTE BLANCHE des colonnes exportées
 * (un secret ajouté plus tard à une table n'est donc jamais exporté par défaut). Lecture par lots, pagination par clé.
 *
 * Définition d'une table : `columns` (obligatoire), `order` (colonne unique, `id` par défaut ; une liste déclenche une
 * pagination par décalage), `tenant_column` (`tenant_id` par défaut), `row` (rappel qui transforme ou écarte — null — une ligne).
 */
abstract class TableExportSource implements ChunkedTenantDataSource
{
    public function __construct(protected readonly ConnectionFactory $connections)
    {
    }

    /** @return array<string, array<string, mixed>> voir la description de la classe */
    abstract protected function tables(): array;

    public function export(string $tenantId): array
    {
        $out = [];
        foreach ($this->exportChunks($tenantId, 1000) as [$table, $rows]) {
            $out[$table] = array_merge($out[$table] ?? [], $rows);
        }

        return $out;
    }

    public function exportChunks(string $tenantId, int $batchSize = 500): iterable
    {
        $batchSize = max(1, $batchSize);
        foreach ($this->tables() as $table => $definition) {
            $emitted = false;
            foreach ($this->read($table, $definition, $tenantId, $batchSize) as $rows) {
                $emitted = true;
                yield [$table, $rows];
            }
            if (!$emitted) {
                yield [$table, []];
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @return iterable<list<array<string, mixed>>>
     */
    private function read(string $table, array $definition, string $tenantId, int $batchSize): iterable
    {
        $tenantColumn = (string) ($definition['tenant_column'] ?? 'tenant_id');
        $order = $definition['order'] ?? 'id';
        $order = is_array($order) ? array_values(array_map('strval', $order)) : (string) $order;
        $columns = array_values(array_map('strval', (array) $definition['columns']));
        foreach ([$table, $tenantColumn, ...$columns, ...(array) $order] as $identifier) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $identifier) !== 1) {
                throw new InvalidArgumentException('Identifiant SQL invalide : ' . $identifier);
            }
        }
        $pdo = $this->connections->pdo();
        $select = implode(', ', $columns);

        if (is_string($order)) {
            if (!in_array($order, $columns, true)) {
                $select .= ', ' . $order;
            }
            $statement = $pdo->prepare("SELECT {$select} FROM {$table} WHERE {$tenantColumn} = ? AND {$order} > ? ORDER BY {$order} LIMIT {$batchSize}");
            $after = '';
            while (true) {
                $statement->execute([$tenantId, $after]);
                $raw = array_values($statement->fetchAll());
                if ($raw === []) {
                    return;
                }
                $after = (string) $raw[array_key_last($raw)][$order];
                $statement->closeCursor();
                yield $this->shape($raw, $definition, in_array($order, $columns, true) ? null : $order);
                if (count($raw) < $batchSize) {
                    return;
                }
            }
        }

        $orderBy = implode(', ', $order);
        $offset = 0;
        while (true) {
            $statement = $pdo->prepare("SELECT {$select} FROM {$table} WHERE {$tenantColumn} = ? ORDER BY {$orderBy} LIMIT {$batchSize} OFFSET {$offset}");
            $statement->execute([$tenantId]);
            $raw = array_values($statement->fetchAll());
            if ($raw === []) {
                return;
            }
            $offset += count($raw);
            yield $this->shape($raw, $definition, null);
            if (count($raw) < $batchSize) {
                return;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @param array<string, mixed> $definition
     * @return list<array<string, mixed>>
     */
    private function shape(array $raw, array $definition, ?string $extraColumn): array
    {
        $rows = [];
        foreach ($raw as $row) {
            if ($extraColumn !== null) {
                unset($row[$extraColumn]);
            }
            if (isset($definition['row'])) {
                $row = ($definition['row'])($row);
                if ($row === null) {
                    continue;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $extra order, tenant_column, row
     * @return array<string, mixed>
     */
    protected static function table(string $columns, array $extra = []): array
    {
        return ['columns' => preg_split('/\s+/', trim($columns)) ?: []] + $extra;
    }
}
