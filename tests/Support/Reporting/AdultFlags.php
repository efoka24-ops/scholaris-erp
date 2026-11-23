<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;

/** Doublure d'AdultLearnerCheck : majeurs déclarés, tous les autres mineurs. */
final class AdultFlags implements AdultLearnerCheck
{
    /** @var array<string, bool> */
    private array $flags = [];

    public function set(string $personId, bool $adult): void
    {
        $this->flags[$personId] = $adult;
    }

    public function isAdult(string $tenantId, string $personId): ?bool
    {
        return $this->flags[$personId] ?? false;
    }
}
