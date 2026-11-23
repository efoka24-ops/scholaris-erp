<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/**
 * Capacité d'une salle, si le référentiel des locaux la connaît. La liaison réelle sera branchée plus tard ;
 * par défaut aucune capacité n'est connue et aucun contrôle de capacité n'est fait.
 */
interface RoomCapacity
{
    /** @return int|null nombre de places, null si inconnu */
    public function capacityOf(string $tenantId, string $establishmentId, string $roomId): ?int;
}
