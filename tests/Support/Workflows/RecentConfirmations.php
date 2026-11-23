<?php

declare(strict_types=1);

namespace Tests\Support\Workflows;

use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;

/** Doublure de la confirmation récente du socle des notes : personnes qui l'ont validée (client:utilisateur). */
final class RecentConfirmations implements RecentSecondFactor
{
    public function confirm(string $tenantId, string $userId): void
    {
        $this->recent[$tenantId . ':' . $userId] = true;
    }

    public function isRecent(string $tenantId, string $userId): bool
    {
        return isset($this->recent[$tenantId . ':' . $userId]);
    }

    /** @var array<string, true> */
    private array $recent = [];
}
