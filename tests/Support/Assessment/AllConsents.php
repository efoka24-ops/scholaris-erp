<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;

/** Doublure de ConsentChecker : tout est consenti. */
final class AllConsents implements ConsentChecker
{
    public function hasConsent(string $tenantId, string $personId, string $purpose): bool
    {
        return true;
    }
}
