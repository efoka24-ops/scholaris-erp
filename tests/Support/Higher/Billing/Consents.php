<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Billing;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;

/** Doublure des consentements (RG-INS-7) : aucun consentement par défaut. */
final class Consents implements ConsentChecker
{
    /** @var array<string, bool> */
    private array $given = [];

    public function give(string $tenantId, string $personId, string $purpose): void
    {
        $this->given[$tenantId . ':' . $personId . ':' . $purpose] = true;
    }

    public function hasConsent(string $tenantId, string $personId, string $purpose): bool
    {
        return $this->given[$tenantId . ':' . $personId . ':' . $purpose] ?? false;
    }
}
