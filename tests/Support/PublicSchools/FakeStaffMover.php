<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMoveInfo;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMover;

/** Doublure de StaffMover : enregistre les mutations demandées et rend l'historique par fiche. */
final class FakeStaffMover implements StaffMover
{
    /** @var array<string, list<StaffMoveInfo>> */
    public array $moves = [];

    /** @var array<string, string> fiche => établissement de rattachement */
    public array $homes = [];

    public function move(string $tenantId, string $staffId, string $toEstablishmentId, string $effectiveOn, ?string $reason, string $actorId): StaffMoveInfo
    {
        $move = new StaffMoveInfo((string) new Ulid(), $staffId, $this->homes[$tenantId . '|' . $staffId] ?? 'e1', $toEstablishmentId, $effectiveOn, $reason, 1);
        $this->moves[$tenantId . '|' . $staffId][] = $move;
        $this->homes[$tenantId . '|' . $staffId] = $toEstablishmentId;

        return $move;
    }

    public function history(string $tenantId, string $staffId): array
    {
        return $this->moves[$tenantId . '|' . $staffId] ?? [];
    }
}
