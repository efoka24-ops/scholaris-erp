<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleScopeLineage;

/**
 * Portées de règles du secondaire (T900, S5) : les règles d'une classe sont cherchées sur la classe (série ou spécialité propre),
 * puis sur la structure de son NIVEAU (barème, coefficients versionnés, passage se règlent par niveau) ; le socle complète par
 * l'année. Une structure sans profil de classe du secondaire n'est pas connue : liste vide, comportement du socle.
 */
final class SecondaryRuleScopes implements RuleScopeLineage
{
    public function __construct(private readonly ClassProfiles $classes)
    {
    }

    public function scopesOf(string $tenantId, string $establishmentId, string $structureId): array
    {
        $class = $this->classes->find($tenantId, $establishmentId, $structureId);
        if ($class === null) {
            return [];
        }
        $level = $this->classes->level($tenantId, $establishmentId, $class->levelId);
        $levelStructure = is_array($level) && is_string($level['structure_id'] ?? null) && $level['structure_id'] !== '' ? $level['structure_id'] : null;

        return $levelStructure === null || $levelStructure === $structureId ? [$structureId] : [$structureId, $levelStructure];
    }
}
