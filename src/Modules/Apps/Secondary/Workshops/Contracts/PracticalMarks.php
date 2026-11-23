<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Workshops\Contracts;

/**
 * Notes pratiques d'atelier d'une classe sur une période (contrat de LECTURE exposé par Workshops, C-117) : pour chaque élève évalué,
 * le nombre d'évaluations, la somme des notes finales et la somme des bases des fiches. Aucune conversion ni pondération : le
 * lecteur affiche « points / base ». Client et établissement imposés.
 */
interface PracticalMarks
{
    /**
     * @return array<string, array{evaluations: int, points: float, out_of: float}> par élève (person_id)
     */
    public function forClass(string $tenantId, string $establishmentId, string $structureId, string $from, string $to): array;
}
