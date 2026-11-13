<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Infrastructure;

use Scholaris\Modules\Socle\Scheduling\Contracts\RoomCapacity;

/** Par défaut, aucune capacité de salle n'est connue : le contrôle de capacité est sans effet. */
final class UnknownRoomCapacity implements RoomCapacity
{
    public function capacityOf(string $tenantId, string $establishmentId, string $roomId): ?int
    {
        return null;
    }
}
