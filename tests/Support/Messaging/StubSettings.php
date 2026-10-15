<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Paramètres en mémoire (module Organisation), par clé. */
final class StubSettings implements SettingsReader
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
