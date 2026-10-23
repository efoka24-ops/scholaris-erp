<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Catalogue\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Accès SQL à la table de référence des séries et spécialités. Deux portées seulement, toujours explicites :
 *  - plateforme : `tenant_id = '*' AND establishment_id = '*'` ;
 *  - établissement : client ET établissement de la requête (jamais ceux d'un autre).
 * La vue d'un établissement réunit les lignes de la plateforme NON retirées et les siennes, avec SON renommage ou masquage
 * (jointure sur ses seules surcharges). Colonnes de tri et de filtre en liste blanche, valeurs liées.
 */
final class PdoStreamCatalogue
{
    public const PLATFORM = '*';
    public const REFS = 'se_stream_refs';
    public const OVERRIDES = 'se_stream_ref_overrides';
    public const CHANGES = 'se_stream_ref_changes';
    /** Colonnes filtrables (égalité) et triables. */
    public const FILTERS = ['code', 'cycle_code', 'teaching_kind', 'track_code', 'stream_kind', 'section_code', 'origin', 'confirmation'];
    public const SORTS = ['code', 'cycle_code', 'teaching_kind', 'track_code', 'label_fr'];
    private const DEFAULT_ORDER = ['r.teaching_kind ASC', 'r.track_code ASC', 'r.cycle_code ASC', 'r.code ASC', 'r.id ASC'];
    private const EFFECTIVE = 'SELECT r.*, o.hidden AS override_hidden, o.label_fr AS override_label_fr, o.label_en AS override_label_en, '
        . 'o.version_no AS override_version FROM se_stream_refs r LEFT JOIN se_stream_ref_overrides o '
        . 'ON o.stream_ref_id = r.id AND o.tenant_id = ? AND o.establishment_id = ? ';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /**
     * Lignes de la plateforme (retirées comprises), pour l'administrateur de plateforme.
     *
     * @param array<string, string> $filters
     * @param list<array{0: string, 1: string}> $sorts
     * @return list<array<string, mixed>>
     */
    public function platformRows(array $filters, array $sorts, int $limit, int $offset): array
    {
        [$clauses, $params] = self::filters($filters);
        $sql = 'SELECT r.* FROM se_stream_refs r WHERE r.tenant_id = ? AND r.establishment_id = ?' . $clauses
            . ' ORDER BY ' . self::order($sorts) . sprintf(' LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset));

        return $this->fetch($sql, [self::PLATFORM, self::PLATFORM, ...$params]);
    }

    /**
     * Vue d'un établissement : lignes de la plateforme non retirées et lignes propres, surcharges appliquées par l'appelant.
     *
     * @param array<string, string> $filters égalités
     * @param array<string, list<string>> $within colonne => valeurs admises (filtre du profil)
     * @param list<array{0: string, 1: string}> $sorts
     * @return list<array<string, mixed>>
     */
    public function establishmentRows(string $tenantId, string $establishmentId, array $filters, array $within, bool $includeHidden, array $sorts, int $limit, int $offset): array
    {
        [$clauses, $params] = self::filters($filters, $within);
        $visible = $includeHidden ? '' : ' AND r.hidden = 0 AND COALESCE(o.hidden, 0) = 0';
        $sql = self::EFFECTIVE . 'WHERE ((r.tenant_id = ? AND r.establishment_id = ? AND r.hidden = 0) OR (r.tenant_id = ? AND r.establishment_id = ?))'
            . $clauses . $visible . ' ORDER BY ' . self::order($sorts) . sprintf(' LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset));

        return $this->fetch($sql, [$tenantId, $establishmentId, self::PLATFORM, self::PLATFORM, $tenantId, $establishmentId, ...$params]);
    }

    /** @return array<string, mixed>|null une ligne visible par l'établissement (de la plateforme non retirée, ou la sienne) */
    public function findForEstablishment(string $tenantId, string $establishmentId, string $id): ?array
    {
        $sql = self::EFFECTIVE . 'WHERE r.id = ? AND ((r.tenant_id = ? AND r.establishment_id = ? AND r.hidden = 0) OR (r.tenant_id = ? AND r.establishment_id = ?))';

        return $this->fetch($sql, [$tenantId, $establishmentId, $id, self::PLATFORM, self::PLATFORM, $tenantId, $establishmentId])[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function findPlatform(string $id): ?array
    {
        return $this->fetch('SELECT r.* FROM se_stream_refs r WHERE r.id = ? AND r.tenant_id = ? AND r.establishment_id = ?', [$id, self::PLATFORM, self::PLATFORM])[0] ?? null;
    }

    /** Vrai si la clé (section, code, cycle) existe déjà dans la portée donnée. */
    public function keyExists(string $tenantId, string $establishmentId, string $section, string $code, string $cycle): bool
    {
        return $this->fetch(
            'SELECT r.id FROM se_stream_refs r WHERE r.tenant_id = ? AND r.establishment_id = ? AND r.section_code = ? AND r.code = ? AND r.cycle_code = ?',
            [$tenantId, $establishmentId, $section, $code, $cycle],
        ) !== [];
    }

    /** @param array<string, scalar|null> $row */
    public function insert(array $row): void
    {
        $this->write(self::REFS, $row);
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function update(string $id, string $tenantId, string $establishmentId, array $values): void
    {
        unset($values['id'], $values['tenant_id'], $values['establishment_id']);
        $sets = implode(', ', array_map(static fn (string $column): string => self::column($column) . ' = ?', array_keys($values)));
        $this->pdo()->prepare('UPDATE se_stream_refs SET ' . $sets . ' WHERE id = ? AND tenant_id = ? AND establishment_id = ?')
            ->execute([...array_values($values), $id, $tenantId, $establishmentId]);
    }

    /** @return array<string, mixed>|null surcharge de l'établissement pour une ligne de la plateforme */
    public function override(string $tenantId, string $establishmentId, string $refId): ?array
    {
        return $this->fetch('SELECT * FROM se_stream_ref_overrides WHERE tenant_id = ? AND establishment_id = ? AND stream_ref_id = ?', [$tenantId, $establishmentId, $refId])[0] ?? null;
    }

    /** @param array<string, scalar|null> $row */
    public function insertOverride(array $row): void
    {
        $this->write(self::OVERRIDES, $row);
    }

    /** @param array<string, scalar|null> $values */
    public function updateOverride(string $id, string $tenantId, string $establishmentId, array $values): void
    {
        unset($values['id'], $values['tenant_id'], $values['establishment_id']);
        $sets = implode(', ', array_map(static fn (string $column): string => self::column($column) . ' = ?', array_keys($values)));
        $this->pdo()->prepare('UPDATE se_stream_ref_overrides SET ' . $sets . ' WHERE id = ? AND tenant_id = ? AND establishment_id = ?')
            ->execute([...array_values($values), $id, $tenantId, $establishmentId]);
    }

    /** @param array<string, scalar|null> $row */
    public function logChange(array $row): void
    {
        $this->write(self::CHANGES, $row);
    }

    /**
     * Historique d'une ligne vu par un établissement : changements de la plateforme et de CET établissement seulement.
     *
     * @return list<array<string, mixed>>
     */
    public function changes(string $refId, string $tenantId, string $establishmentId): array
    {
        return $this->fetch(
            'SELECT * FROM se_stream_ref_changes WHERE stream_ref_id = ? AND ((tenant_id = ? AND establishment_id = ?) OR (tenant_id = ? AND establishment_id = ?)) ORDER BY created_at, id LIMIT 500',
            [$refId, self::PLATFORM, self::PLATFORM, $tenantId, $establishmentId],
        );
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
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, string> $filters
     * @param array<string, list<string>> $within
     * @return array{0: string, 1: list<string>}
     */
    private static function filters(array $filters, array $within = []): array
    {
        $clauses = '';
        $params = [];
        foreach ($filters as $column => $value) {
            if (!in_array($column, self::FILTERS, true)) {
                throw new \InvalidArgumentException('Filtre non autorisé : ' . $column);
            }
            $clauses .= ' AND r.' . $column . ' = ?';
            $params[] = $value;
        }
        foreach ($within as $column => $values) {
            if (!in_array($column, self::FILTERS, true)) {
                throw new \InvalidArgumentException('Filtre non autorisé : ' . $column);
            }
            if ($values === []) {
                $clauses .= ' AND 1 = 0';
                continue;
            }
            $clauses .= ' AND r.' . $column . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
            array_push($params, ...$values);
        }

        return [$clauses, $params];
    }

    /** @param list<array{0: string, 1: string}> $sorts */
    private static function order(array $sorts): string
    {
        $order = [];
        foreach ($sorts as [$column, $direction]) {
            if (!in_array($column, self::SORTS, true)) {
                throw new \InvalidArgumentException('Tri non autorisé : ' . $column);
            }
            $order[] = 'r.' . $column . ' ' . ($direction === 'desc' ? 'DESC' : 'ASC');
        }

        return implode(', ', [...$order, ...self::DEFAULT_ORDER]);
    }

    /** @param array<string, scalar|null> $row */
    private function write(string $table, array $row): void
    {
        $columns = array_map(static fn (string $column): string => self::column($column), array_keys($row));
        $this->pdo()->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($row), '?'))))
            ->execute(array_values($row));
    }

    private static function column(string $column): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
            throw new \InvalidArgumentException('Colonne invalide : ' . $column);
        }

        return $column;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function fetch(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
