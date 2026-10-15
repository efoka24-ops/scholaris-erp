<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureInfo;

/**
 * Arbre d'un établissement lu par TenantTable (client et établissement imposés). Les ensembles sont petits (quelques centaines
 * de lignes) : ils sont chargés en une requête puis parcourus en mémoire, avec une profondeur bornée contre les cycles.
 */
final class PdoOrgUnitTree implements OrgUnitTree
{
    private const MAX_DEPTH = 100;

    public function __construct(private readonly Repositories $repositories)
    {
    }

    public function descendantsOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        return $this->descend($this->parents('org_units', $tenantId, $establishmentId), $orgUnitId);
    }

    public function structuresOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        $units = $this->descendantsOf($tenantId, $establishmentId, $orgUnitId);
        if ($units === []) {
            return [];
        }
        $inUnits = array_flip($units);
        $rows = $this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId)->all();
        $parents = [];
        foreach ($rows as $row) {
            $parents[(string) $row['id']] = self::nullable($row['parent_id'] ?? null);
        }

        $result = [];
        foreach ($rows as $row) {
            $unit = self::nullable($row['org_unit_id']);
            if ($unit === null || !isset($inUnits[$unit])) {
                continue;
            }
            foreach ($this->descend($parents, (string) $row['id']) as $id) {
                $result[$id] = $id;
            }
        }

        return array_values($result);
    }

    public function structureDescendantsOf(string $tenantId, string $establishmentId, string $structureId): array
    {
        return $this->descend($this->parents('pedagogical_structures', $tenantId, $establishmentId), $structureId);
    }

    public function ancestorsOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        $parents = $this->parents('org_units', $tenantId, $establishmentId);
        if (!array_key_exists($orgUnitId, $parents)) {
            return [];
        }
        $result = [];
        $cursor = $parents[$orgUnitId];
        for ($depth = 0; $cursor !== null && array_key_exists($cursor, $parents) && $depth < self::MAX_DEPTH && !in_array($cursor, $result, true); $depth++) {
            $result[] = $cursor;
            $cursor = $parents[$cursor];
        }

        return $result;
    }

    public function structure(string $tenantId, string $establishmentId, string $structureId): ?StructureInfo
    {
        $row = $this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId)->find($structureId);

        return $row === null ? null : self::info($row);
    }

    public function trackOf(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        return $this->nearest($tenantId, $establishmentId, $structureId, 'track');
    }

    public function levelOf(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        return $this->nearest($tenantId, $establishmentId, $structureId, 'level');
    }

    /** @return list<string> la structure et ses ascendantes, de la plus proche à la racine ; [] si inconnue */
    public function lineage(string $tenantId, string $establishmentId, string $structureId): array
    {
        $rows = $this->byId($tenantId, $establishmentId);
        $result = [];
        $cursor = $structureId;
        for ($depth = 0; $cursor !== null && isset($rows[$cursor]) && $depth < self::MAX_DEPTH && !in_array($cursor, $result, true); $depth++) {
            $result[] = $cursor;
            $cursor = self::nullable($rows[$cursor]['parent_id'] ?? null);
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    public static function info(array $row): StructureInfo
    {
        return new StructureInfo(
            (string) $row['id'],
            (string) $row['establishment_id'],
            (string) $row['kind'],
            (string) $row['name'],
            self::nullable($row['org_unit_id']),
            self::nullable($row['parent_id'] ?? null),
            $row['capacity'] === null ? null : (int) $row['capacity'],
        );
    }

    private function nearest(string $tenantId, string $establishmentId, string $structureId, string $kind): ?string
    {
        $rows = $this->byId($tenantId, $establishmentId);
        foreach ($this->lineage($tenantId, $establishmentId, $structureId) as $id) {
            if ((string) $rows[$id]['kind'] === $kind) {
                return $id;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function byId(string $tenantId, string $establishmentId): array
    {
        $rows = [];
        foreach ($this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId)->all() as $row) {
            $rows[(string) $row['id']] = $row;
        }

        return $rows;
    }

    /** @return array<string, ?string> identifiant => parent */
    private function parents(string $table, string $tenantId, string $establishmentId): array
    {
        $parents = [];
        foreach ($this->repositories->explicit($table, $tenantId, $establishmentId)->all() as $row) {
            $parents[(string) $row['id']] = self::nullable($row['parent_id'] ?? null);
        }

        return $parents;
    }

    /**
     * @param array<string, ?string> $parents
     * @return list<string> le nœud puis ses descendants (largeur d'abord) ; [] si le nœud est inconnu
     */
    private function descend(array $parents, string $root): array
    {
        if (!array_key_exists($root, $parents)) {
            return [];
        }
        $children = [];
        foreach ($parents as $id => $parent) {
            if ($parent !== null) {
                $children[$parent][] = (string) $id;
            }
        }
        $result = [$root => true];
        $queue = [$root];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($children[$current] ?? [] as $child) {
                if (!isset($result[$child])) {
                    $result[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return array_map('strval', array_keys($result));
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
