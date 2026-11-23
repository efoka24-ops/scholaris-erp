<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Academic;

use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/** Doublure des paramètres (D6) : valeurs par établissement et surcharges par école ; sinon la valeur par défaut. */
final class FakeSettings implements OrgUnitSettingsReader
{
    /** @var array<string, mixed> « établissement|unité|clé » => valeur */
    private array $values = [];

    public function set(string $establishmentId, string $key, mixed $value, ?string $orgUnitId = null): void
    {
        $this->values[$establishmentId . '|' . ($orgUnitId ?? '') . '|' . $key] = $value;
    }

    public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
    {
        return $this->valueForOrgUnit($tenantId, (string) $establishmentId, null, $key, $default);
    }

    public function valueForOrgUnit(string $tenantId, string $establishmentId, ?string $orgUnitId, string $key, mixed $default = null): mixed
    {
        if ($orgUnitId !== null && array_key_exists($establishmentId . '|' . $orgUnitId . '|' . $key, $this->values)) {
            return $this->values[$establishmentId . '|' . $orgUnitId . '|' . $key];
        }

        return $this->values[$establishmentId . '||' . $key] ?? $default;
    }
}
