<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor as IdentitySecondFactor;

/** « Récent » = validée depuis moins de dix minutes. Refus si Identity n'en a aucune preuve. */
final class IdentityRecentSecondFactor implements RecentSecondFactor
{
    public const MAX_AGE_SECONDS = 600;

    public function __construct(private readonly IdentitySecondFactor $identity)
    {
    }

    public function isRecent(string $tenantId, string $userId): bool
    {
        return $this->identity->hasRecent($tenantId, $userId, self::MAX_AGE_SECONDS);
    }
}
