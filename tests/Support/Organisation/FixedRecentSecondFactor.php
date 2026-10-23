<?php

declare(strict_types=1);

namespace Tests\Support\Organisation;

use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;

/** Doublure : seuls les utilisateurs déclarés ont une double authentification récente. */
final class FixedRecentSecondFactor implements RecentSecondFactor
{
    /** @var array<string, true> */
    private array $recent = [];

    public function allow(string $userId): void
    {
        $this->recent[$userId] = true;
    }

    public function revoke(string $userId): void
    {
        unset($this->recent[$userId]);
    }

    public function hasRecent(string $tenantId, string $userId, int $maxAgeSeconds): bool
    {
        return isset($this->recent[$userId]);
    }
}
