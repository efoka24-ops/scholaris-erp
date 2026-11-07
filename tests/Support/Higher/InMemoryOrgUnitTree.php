<?php

declare(strict_types=1);

namespace Tests\Support\Higher;

use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureInfo;

/** Doublure d'OrgUnitTree : unités (école > département) et structures (filière > niveau > groupe), bornées au client et à l'établissement. */
final class InMemoryOrgUnitTree implements OrgUnitTree
{
    /** @var array<string, array<string, ?string>> « client:établissement » => unité => parent */
    private array $units = [];

    /** @var array<string, array<string, array{kind: string, parent: ?string, unit: ?string}>> */
    private array $structures = [];

    public function unit(string $tenantId, string $establishmentId, string $id, ?string $parent = null): void
    {
        $this->units[$tenantId . ':' . $establishmentId][$id] = $parent;
    }

    public function addStructure(string $tenantId, string $establishmentId, string $id, string $kind, ?string $parent, ?string $orgUnitId): void
    {
        $this->structures[$tenantId . ':' . $establishmentId][$id] = ['kind' => $kind, 'parent' => $parent, 'unit' => $orgUnitId];
    }

    public function descendantsOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        return self::descend($this->units[$tenantId . ':' . $establishmentId] ?? [], $orgUnitId);
    }

    public function structuresOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        $units = array_flip($this->descendantsOf($tenantId, $establishmentId, $orgUnitId));
        $result = [];
        foreach ($this->structures[$tenantId . ':' . $establishmentId] ?? [] as $id => $row) {
            if ($row['unit'] !== null && isset($units[$row['unit']])) {
                foreach ($this->structureDescendantsOf($tenantId, $establishmentId, (string) $id) as $child) {
                    $result[$child] = $child;
                }
            }
        }

        return array_values($result);
    }

    public function structureDescendantsOf(string $tenantId, string $establishmentId, string $structureId): array
    {
        $parents = array_map(static fn (array $row): ?string => $row['parent'], $this->structures[$tenantId . ':' . $establishmentId] ?? []);

        return self::descend($parents, $structureId);
    }

    public function ancestorsOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        $parents = $this->units[$tenantId . ':' . $establishmentId] ?? [];
        $result = [];
        $cursor = $parents[$orgUnitId] ?? null;
        while ($cursor !== null && !in_array($cursor, $result, true)) {
            $result[] = $cursor;
            $cursor = $parents[$cursor] ?? null;
        }

        return $result;
    }

    public function structure(string $tenantId, string $establishmentId, string $structureId): ?StructureInfo
    {
        $row = $this->structures[$tenantId . ':' . $establishmentId][$structureId] ?? null;

        return $row === null ? null : new StructureInfo($structureId, $establishmentId, $row['kind'], $structureId, $row['unit'], $row['parent'], null);
    }

    public function trackOf(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        return $this->nearest($tenantId, $establishmentId, $structureId, 'track');
    }

    public function levelOf(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        return $this->nearest($tenantId, $establishmentId, $structureId, 'level');
    }

    private function nearest(string $tenantId, string $establishmentId, string $structureId, string $kind): ?string
    {
        $rows = $this->structures[$tenantId . ':' . $establishmentId] ?? [];
        $cursor = $structureId;
        for ($depth = 0; $cursor !== null && isset($rows[$cursor]) && $depth < 50; $depth++) {
            if ($rows[$cursor]['kind'] === $kind) {
                return $cursor;
            }
            $cursor = $rows[$cursor]['parent'];
        }

        return null;
    }

    /**
     * @param array<string, ?string> $parents
     * @return list<string>
     */
    private static function descend(array $parents, string $root): array
    {
        if (!array_key_exists($root, $parents)) {
            return [];
        }
        $result = [$root];
        for ($i = 0; $i < count($result); $i++) {
            foreach ($parents as $id => $parent) {
                if ($parent === $result[$i] && !in_array((string) $id, $result, true)) {
                    $result[] = (string) $id;
                }
            }
        }

        return $result;
    }
}
