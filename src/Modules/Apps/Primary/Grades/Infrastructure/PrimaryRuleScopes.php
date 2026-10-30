<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Infrastructure;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleScopeLineage;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Portées de règles du primaire (EX-PRI-21) : les règles d'une classe sont d'abord cherchées sur la classe, puis sur son NIVEAU (barème,
 * note de passage et coefficients se règlent par niveau) ; le socle complète ensuite par l'année. Une structure sans profil de niveau
 * primaire n'est pas connue de ce fournisseur : il répond une liste vide et laisse le socle appliquer son comportement.
 */
final class PrimaryRuleScopes implements RuleScopeLineage
{
    public function __construct(private readonly OrgUnitTree $tree, private readonly LevelDirectory $levels)
    {
    }

    public function scopesOf(string $tenantId, string $establishmentId, string $structureId): array
    {
        $structure = $this->tree->structure($tenantId, $establishmentId, $structureId);
        if ($structure === null) {
            return [];
        }
        $levelId = $this->tree->levelOf($tenantId, $establishmentId, $structureId);
        if ($levelId === null || $this->levels->profile($tenantId, $establishmentId, $levelId) === null) {
            return [];
        }

        return $levelId === $structureId ? [$levelId] : [$structureId, $levelId];
    }
}
