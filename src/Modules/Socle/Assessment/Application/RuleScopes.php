<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\Assessment\Contracts\RuleScopeLineage;

/** Structures dont les règles s'appliquent à une structure donnée, la plus précise d'abord (S5). */
final class RuleScopes
{
    /** @param list<RuleScopeLineage> $providers */
    public function __construct(private readonly array $providers = [])
    {
    }

    /** @return list<string> */
    public function scopesOf(string $tenantId, string $establishmentId, string $structureId): array
    {
        foreach ($this->providers as $provider) {
            $scopes = array_values(array_unique(array_filter($provider->scopesOf($tenantId, $establishmentId, $structureId), static fn (string $s): bool => $s !== '')));
            if ($scopes !== []) {
                return in_array($structureId, $scopes, true) ? $scopes : [$structureId, ...$scopes];
            }
        }

        return [$structureId];
    }
}
