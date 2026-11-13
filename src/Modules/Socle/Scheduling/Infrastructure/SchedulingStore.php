<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Infrastructure;

use InvalidArgumentException;
use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\ListQuery;

/**
 * Accès PDO aux tables du module. Règle : TOUTE requête reçoit explicitement le client (`tenant_id`) et, sauf
 * lecture inter-établissements assumée (`null`), l'établissement ; tables et colonnes sont validées, valeurs liées.
 */
final class SchedulingStore
{
    private const TABLES = ['calendar_events', 'timetable_slots', 'timetable_slot_structures', 'timetable_changes'];
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->table($table);
        foreach (array_keys($row) as $column) {
            $this->identifier((string) $column);
        }
        $this->pdo()->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_keys($row)),
            implode(', ', array_fill(0, count($row), '?')),
        ))->execute(array_values($row));
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, string $tenantId, ?string $establishmentId, string $id): ?array
    {
        return $this->where($table, $tenantId, $establishmentId, ['id' => $id], 'id', 1)[0] ?? null;
    }

    /**
     * @param array<string, scalar|null> $conditions égalités (null = IS NULL)
     * @return list<array<string, mixed>>
     */
    public function where(string $table, string $tenantId, ?string $establishmentId, array $conditions = [], string $orderBy = 'id', int $limit = 5000): array
    {
        $this->table($table);
        $this->identifier($orderBy);
        [$clause, $params] = $this->clause($tenantId, $establishmentId, $conditions);

        return $this->rows(sprintf('SELECT * FROM %s WHERE %s ORDER BY %s LIMIT %d', $table, $clause, $orderBy, max(1, $limit)), $params);
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $table, string $tenantId, string $establishmentId, string $id, array $values): void
    {
        $this->table($table);
        unset($values['id'], $values['tenant_id'], $values['establishment_id']);
        if ($values === []) {
            return;
        }
        foreach (array_keys($values) as $column) {
            $this->identifier((string) $column);
        }
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $this->pdo()->prepare(sprintf('UPDATE %s SET %s WHERE tenant_id = ? AND establishment_id = ? AND id = ?', $table, $sets))
            ->execute([...array_values($values), $tenantId, $establishmentId, $id]);
    }

    /** @param array<string, scalar|null> $conditions non vide */
    public function delete(string $table, string $tenantId, string $establishmentId, array $conditions): void
    {
        $this->table($table);
        if ($conditions === []) {
            throw new InvalidArgumentException('DELETE sans condition refusé.');
        }
        [$clause, $params] = $this->clause($tenantId, $establishmentId, $conditions);
        $this->pdo()->prepare(sprintf('DELETE FROM %s WHERE %s', $table, $clause))->execute($params);
    }

    /**
     * Page triée par identifiant, curseur opaque.
     *
     * @param array<string, scalar|null> $conditions
     * @param list<scalar> $extraParams
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function page(string $table, string $tenantId, string $establishmentId, array $conditions, string $extraSql, array $extraParams, ListQuery $query): array
    {
        $this->table($table);
        [$clause, $params] = $this->clause($tenantId, $establishmentId, $conditions);
        if ($extraSql !== '') {
            $clause .= ' AND ' . $extraSql;
            $params = [...$params, ...$extraParams];
        }
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $clause .= ' AND id > ?';
            $params[] = $after;
        }
        $rows = $this->rows(sprintf('SELECT * FROM %s WHERE %s ORDER BY id LIMIT %d', $table, $clause, $query->perPage + 1), $params);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[$query->perPage - 1]['id']);
        }

        return [$rows, $next];
    }

    /**
     * Requête libre : l'appelant DOIT y mettre `tenant_id = ?`.
     *
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (PDOException) {
                // transaction déjà terminée
            }
            throw $e;
        }
    }

    /**
     * Créneaux en vigueur un jour donné (jour de la semaine et période de validité).
     *
     * @return list<array<string, mixed>>
     */
    public function slotsRunningOn(string $tenantId, ?string $establishmentId, int $weekday, string $date): array
    {
        $sql = 'SELECT * FROM timetable_slots WHERE tenant_id = ? AND weekday = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)';
        $params = [$tenantId, $weekday, $date, $date];
        if ($establishmentId !== null) {
            $sql .= ' AND establishment_id = ?';
            $params[] = $establishmentId;
        }

        return $this->rows($sql . ' ORDER BY start_time, id', $params);
    }

    /**
     * Créneaux susceptibles d'entrer en conflit : même jour de la semaine, plages et validités qui se recouvrent.
     *
     * @return list<array<string, mixed>>
     */
    public function conflictCandidates(string $tenantId, string $establishmentId, int $weekday, string $start, string $end, string $validFrom, ?string $validTo): array
    {
        return $this->rows(
            'SELECT * FROM timetable_slots WHERE tenant_id = ? AND establishment_id = ? AND weekday = ? AND start_time < ? AND end_time > ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)',
            [$tenantId, $establishmentId, $weekday, $end, $start, $validTo ?? '9999-12-31', $validFrom],
        );
    }

    /**
     * Structures de chaque créneau.
     *
     * @param list<string> $slotIds
     * @return array<string, list<string>>
     */
    public function structuresBySlot(string $tenantId, array $slotIds): array
    {
        $map = [];
        foreach (array_chunk($slotIds, 200) as $chunk) {
            $rows = $this->rows(
                sprintf('SELECT slot_id, structure_id FROM timetable_slot_structures WHERE tenant_id = ? AND slot_id IN (%s) ORDER BY structure_id', implode(', ', array_fill(0, count($chunk), '?'))),
                [$tenantId, ...$chunk],
            );
            foreach ($rows as $row) {
                $map[(string) $row['slot_id']][] = (string) $row['structure_id'];
            }
        }

        return $map;
    }

    /**
     * Entrées du calendrier qui suspendent les cours à cette date.
     *
     * @return list<array<string, mixed>>
     */
    public function closuresOn(string $tenantId, ?string $establishmentId, string $date): array
    {
        $sql = "SELECT * FROM calendar_events WHERE tenant_id = ? AND kind IN ('holiday', 'closure') AND starts_on <= ? AND ends_on >= ?";
        $params = [$tenantId, $date, $date];
        if ($establishmentId !== null) {
            $sql .= ' AND establishment_id = ?';
            $params[] = $establishmentId;
        }

        return $this->rows($sql, $params);
    }

    /**
     * Modifications qui touchent une date : occurrence d'origine ou nouvelle date d'un déplacement.
     *
     * @return list<array<string, mixed>>
     */
    public function changesAround(string $tenantId, ?string $establishmentId, string $date): array
    {
        $sql = "SELECT * FROM timetable_changes WHERE tenant_id = ? AND (on_date = ? OR (kind = 'moved' AND new_date = ?))";
        $params = [$tenantId, $date, $date];
        if ($establishmentId !== null) {
            $sql .= ' AND establishment_id = ?';
            $params[] = $establishmentId;
        }

        return $this->rows($sql, $params);
    }

    /**
     * @param array<string, scalar|null> $conditions
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function clause(string $tenantId, ?string $establishmentId, array $conditions): array
    {
        $clauses = ['tenant_id = ?'];
        $params = [$tenantId];
        if ($establishmentId !== null) {
            $clauses[] = 'establishment_id = ?';
            $params[] = $establishmentId;
        }
        foreach ($conditions as $column => $value) {
            $this->identifier((string) $column);
            if ($value === null) {
                $clauses[] = $column . ' IS NULL';
                continue;
            }
            $clauses[] = $column . ' = ?';
            $params[] = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function table(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException('Table inconnue : ' . $table);
        }
    }

    private function identifier(string $name): void
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new InvalidArgumentException('Identifiant SQL invalide : ' . $name);
        }
    }
}
