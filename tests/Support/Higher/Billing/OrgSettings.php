<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Billing;

use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/** Doublure des paramètres : valeur de l'établissement, surchargée par école (« clé@école », RG-ORG-5). */
final class OrgSettings implements OrgUnitSettingsReader
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function valueForOrgUnit(string $tenantId, string $establishmentId, ?string $orgUnitId, string $key, mixed $default = null): mixed
    {
        if ($orgUnitId !== null && array_key_exists($key . '@' . $orgUnitId, $this->values)) {
            return $this->values[$key . '@' . $orgUnitId];
        }

        return $this->value($tenantId, $establishmentId, $key, $default);
    }
}
