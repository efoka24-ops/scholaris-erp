<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Academic;

use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;

/** Doublure de la double authentification récente : utilisateurs qui l'ont validée. */
final class FakeSecondFactor implements RecentSecondFactor
{
    /** @var array<string, true> */
    private array $recent = [];

    public function validate(string $tenantId, string $userId): void
    {
        $this->recent[$tenantId . ':' . $userId] = true;
    }

    public function hasRecent(string $tenantId, string $userId, int $maxAgeSeconds): bool
    {
        return isset($this->recent[$tenantId . ':' . $userId]);
    }
}
