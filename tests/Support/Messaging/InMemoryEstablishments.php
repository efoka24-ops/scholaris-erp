<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentInfo;

/** Annuaire d'établissements en mémoire. */
final class InMemoryEstablishments implements EstablishmentDirectory
{
    /** @var array<string, EstablishmentInfo> */
    private array $items = [];

    public function add(string $tenantId, string $id, string $name): void
    {
        $this->items[$id] = new EstablishmentInfo($id, $tenantId, $name, 'secondary', '#F26B1D');
    }

    public function forTenant(string $tenantId): array
    {
        return array_values(array_filter($this->items, static fn (EstablishmentInfo $e): bool => $e->tenantId === $tenantId));
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
