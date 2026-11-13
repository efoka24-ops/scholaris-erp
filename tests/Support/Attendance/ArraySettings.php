<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Doublure de SettingsReader : paramètres par clé, identiques pour tous les établissements. */
final class ArraySettings implements SettingsReader
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
