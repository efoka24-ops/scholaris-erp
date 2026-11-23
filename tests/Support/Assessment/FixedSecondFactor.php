<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;

/** Doublure de RecentSecondFactor. */
final class FixedSecondFactor implements RecentSecondFactor
{
    public bool $recent = false;

    public function isRecent(string $tenantId, string $userId): bool
    {
        return $this->recent;
    }
}
