<?php

declare(strict_types=1);

namespace Tests\Support\Scheduling;

use Scholaris\Modules\Socle\Scheduling\Contracts\RoomCapacity;

/** Doublure de RoomCapacity : capacités déclarées salle par salle. */
final class ArrayRooms implements RoomCapacity
{
    /** @var array<string, int> */
    private array $capacities = [];

    public function set(string $roomId, int $capacity): void
    {
        $this->capacities[$roomId] = $capacity;
    }

    public function capacityOf(string $tenantId, string $establishmentId, string $roomId): ?int
    {
        return $this->capacities[$roomId] ?? null;
    }
}
