<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleScopeLineage;

/**
 * Règles de calcul par filière (S5, T279) : pour un groupe Higher, le socle cherche les règles du groupe, puis du niveau, puis de
 * la filière (règlement par programme et année). Enregistré sous `assessment.rule_scopes.higher_deliberation`.
 */
final class HigherRuleScopes implements RuleScopeLineage
{
    public function __construct(private readonly AcademicDirectory $academic)
    {
    }

    public function scopesOf(string $tenantId, string $establishmentId, string $structureId): array
    {
        $group = $this->academic->group($tenantId, $establishmentId, $structureId);
        if ($group !== null) {
            return [$group->id, $group->levelId, $group->programId];
        }
        $level = $this->academic->level($tenantId, $establishmentId, $structureId);

        return $level !== null ? [$level->id, $level->programId] : [];
    }
}
