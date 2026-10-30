<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Organisation\Contracts\ModuleAvailability;

/** Doublure de ModuleAvailability : tout est activé sauf ce qu'on désactive. */
final class Modules implements ModuleAvailability
{
    /** @var array<string, true> */
    private array $disabled = [];

    public function disable(string $establishmentId, string $module): void
    {
        $this->disabled[$establishmentId . '|' . $module] = true;
    }

    public function isEnabled(string $tenantId, string $establishmentId, string $module): bool
    {
        return !isset($this->disabled[$establishmentId . '|' . $module]);
    }
}
