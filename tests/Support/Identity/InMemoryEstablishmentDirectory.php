<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentInfo;

/** Doublure en mémoire de l'annuaire des établissements (module Organisation). */
final class InMemoryEstablishmentDirectory implements EstablishmentDirectory
{
    /** @var array<string, EstablishmentInfo> */
    private array $items = [];

    public function add(string $tenantId, string $id, string $name, string $type = 'secondary', string $accent = '#F26B1D', ?string $logo = null): void
    {
        $this->items[$id] = new EstablishmentInfo($id, $tenantId, $name, $type, $accent, $logo);
    }

    public function forTenant(string $tenantId): array
    {
        $list = array_values(array_filter($this->items, static fn (EstablishmentInfo $e): bool => $e->tenantId === $tenantId));
        usort($list, static fn (EstablishmentInfo $a, EstablishmentInfo $b): int => strcmp($a->name, $b->name));

        return $list;
    }

    public function findMany(string $tenantId, array $ids): array
    {
        return array_values(array_filter($this->forTenant($tenantId), static fn (EstablishmentInfo $e): bool => in_array($e->id, $ids, true)));
    }

    public function find(string $tenantId, string $establishmentId): ?EstablishmentInfo
    {
        $info = $this->items[$establishmentId] ?? null;

        return $info !== null && $info->tenantId === $tenantId ? $info : null;
    }
}
