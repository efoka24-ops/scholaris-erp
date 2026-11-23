<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo;

/** Doublure de TerritoryDirectory : arbre fictif region > departement > arrondissement, écoles rattachées, bornés au client. */
final class InMemoryTerritories implements TerritoryDirectory
{
    /** @var array<string, array<string, TerritoryInfo>> client => id => territoire */
    private array $nodes = [];

    /** @var array<string, array<string, string>> client => établissement => territoire */
    private array $links = [];

    public function add(string $tenantId, string $id, string $type, ?string $parentId): void
    {
        $this->nodes[$tenantId][$id] = new TerritoryInfo($tenantId, $id, $type, 'SYN ' . $id, 'SYN ' . $id, $parentId, '2020-01-01T00:00:00+01:00', null, '1', 'SYN-' . $id);
    }

    public function attach(string $tenantId, string $establishmentId, string $territoryId): void
    {
        $this->links[$tenantId][$establishmentId] = $territoryId;
    }

    public function find(string $tenantId, string $territoryId, string $at): ?TerritoryInfo
    {
        return $this->nodes[$tenantId][$territoryId] ?? null;
    }

    public function lineage(string $tenantId, string $territoryId, string $at): array
    {
        $lineage = [];
        for ($node = $this->find($tenantId, $territoryId, $at); $node !== null; $node = $node->parentId === null ? null : $this->find($tenantId, $node->parentId, $at)) {
            $lineage[] = $node;
        }

        return $lineage;
    }

    public function descendantsOf(string $tenantId, string $territoryId, string $at): array
    {
        $root = $this->find($tenantId, $territoryId, $at);
        if ($root === null) {
            return [];
        }
        $result = [$root];
        foreach ($this->nodes[$tenantId] as $node) {
            if ($node->id !== $territoryId && in_array($territoryId, array_map(static fn (TerritoryInfo $n): string => $n->id, $this->lineage($tenantId, $node->id, $at)), true)) {
                $result[] = $node;
            }
        }

        return $result;
    }

    public function territoryOf(string $tenantId, string $establishmentId, string $at): ?TerritoryInfo
    {
        $id = $this->links[$tenantId][$establishmentId] ?? null;

        return $id === null ? null : $this->find($tenantId, $id, $at);
    }

    public function establishmentsIn(string $tenantId, string $territoryId, string $at): array
    {
        $ids = array_map(static fn (TerritoryInfo $n): string => $n->id, $this->descendantsOf($tenantId, $territoryId, $at));
        $result = [];
        foreach ($this->links[$tenantId] ?? [] as $establishmentId => $linked) {
            if (in_array($linked, $ids, true)) {
                $result[] = $establishmentId;
            }
        }
        sort($result);

        return $result;
    }
}
