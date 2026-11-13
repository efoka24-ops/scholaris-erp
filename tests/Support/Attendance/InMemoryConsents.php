<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;

/** Doublure de ConsentChecker : consentements accordés explicitement. */
final class InMemoryConsents implements ConsentChecker
{
    /** @var array<string, true> */
    private array $granted = [];

    public function grant(string $tenantId, string $personId, string $purpose): void
    {
        $this->granted[$tenantId . '|' . $personId . '|' . $purpose] = true;
    }

    public function hasConsent(string $tenantId, string $personId, string $purpose): bool
    {
        return isset($this->granted[$tenantId . '|' . $personId . '|' . $purpose]);
    }
}
