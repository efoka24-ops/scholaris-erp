<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Accès PDO à `reporting_territory_aggregates`. Toute requête porte `tenant_id`. */
final class TerritoryAggregateRepository
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return list<string> clients non fermés, triés */
    public function activeTenants(): array
    {
        $rows = $this->connections->pdo()->query("SELECT id FROM tenants WHERE status <> 'closed' ORDER BY id");

        $ids = [];
        foreach ($rows === false ? [] : $rows->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Remplace toutes les lignes du client pour la date de référence.
     *
     * @param array<string, array<string, array{cells: array<string, int>, establishments: int}>> $byTerritory territoire => indicateur => cases
     * @return int lignes écrites
     */
    public function replace(string $tenantId, string $referenceDate, array $byTerritory, string $computedAt): int
    {
        $pdo = $this->connections->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM reporting_territory_aggregates WHERE tenant_id = ? AND reference_date = ?')->execute([$tenantId, $referenceDate]);
            $insert = $pdo->prepare('INSERT INTO reporting_territory_aggregates (id, tenant_id, territory_id, indicator_key, reference_date, cell_key, cell_count, establishment_count, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $written = 0;
            foreach ($byTerritory as $territoryId => $indicators) {
                foreach ($indicators as $indicator => $data) {
                    foreach ($data['cells'] as $cell => $count) {
                        $insert->execute([(string) new Ulid(), $tenantId, (string) $territoryId, (string) $indicator, $referenceDate, (string) $cell, $count, $data['establishments'], $computedAt]);
                        ++$written;
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $written;
    }

    /** Dernière date de référence calculée pour le territoire, ou null. */
    public function latestDate(string $tenantId, string $territoryId): ?string
    {
        $statement = $this->connections->pdo()->prepare('SELECT MAX(reference_date) FROM reporting_territory_aggregates WHERE tenant_id = ? AND territory_id = ?');
        $statement->execute([$tenantId, $territoryId]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<array{indicator_key: string, cell_key: string, cell_count: int, establishment_count: int, computed_at: string}> */
    public function cells(string $tenantId, string $territoryId, string $referenceDate): array
    {
        $statement = $this->connections->pdo()->prepare('SELECT indicator_key, cell_key, cell_count, establishment_count, computed_at FROM reporting_territory_aggregates WHERE tenant_id = ? AND territory_id = ? AND reference_date = ? ORDER BY indicator_key, cell_key');
        $statement->execute([$tenantId, $territoryId, $referenceDate]);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'indicator_key' => (string) $row['indicator_key'],
                'cell_key' => (string) $row['cell_key'],
                'cell_count' => (int) $row['cell_count'],
                'establishment_count' => (int) $row['establishment_count'],
                'computed_at' => (string) $row['computed_at'],
            ];
        }

        return $rows;
    }
}
