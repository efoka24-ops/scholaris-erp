<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Contracts;

use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

/**
 * Effectifs d'inscrits en une requête d'agrégat (tableaux de bord PIL, statistiques MINESUP). « Inscrit » : inscription en cours
 * (inscrite, sous réserve financière ou suspendue). Le périmètre est appliqué ici ; la période filtre la validation (`validated_at`).
 */
interface EnrolmentStatistics
{
    /** @return list<array{school_id: string, program_id: string, level_id: string, enrolled: int}> */
    public function headcount(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array;

    /**
     * Inscrits à ventiler par caractéristique de la personne (sexe, âge) : une ligne par inscription, jamais exposée telle quelle
     * (l'identifiant de personne sert uniquement au calcul des agrégats MINESUP).
     *
     * @return list<array{person_id: string, program_id: string, level_id: string}>
     */
    public function enrolledPersons(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array;
}
