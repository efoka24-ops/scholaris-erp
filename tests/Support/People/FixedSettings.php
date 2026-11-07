<?php

declare(strict_types=1);

namespace Tests\Support\People;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Doublure des paramètres : valeurs fixées par le test (clé => valeur), sinon la valeur par défaut demandée. */
final class FixedSettings implements SettingsReader
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}
