<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Contracts;

/**
 * Conduite d'un élève pour les autres sous-modules (Councils T902 : conduite au conseil de classe, refus de distinction) :
 * sanctions du barème APPLIQUÉES sur une période (décision d'une personne habilitée ou du conseil de discipline). Lecture seule.
 */
interface ConductRecord
{
    /**
     * Bornes incluses AAAA-MM-JJ : propositions dont la période est comprise dans [from, to].
     *
     * @return list<array{code: string, label: string, unjustified_hours: float, period_from: string, period_to: string, decided_at: ?string}>
     */
    public function appliedFor(string $tenantId, string $establishmentId, string $personId, string $from, string $to): array;
}
