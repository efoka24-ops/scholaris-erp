<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Arbre des périodes pédagogiques d'une année (année → trimestres ou semestres → séquences), en lecture seule : bulletins qui
 * montrent toutes les périodes de l'année (SEQ1 à SEQ6, TRIM1 à TRIM3). Toujours borné au client et à l'établissement demandés.
 * Fourni par Organisation.
 */
interface PeriodTree
{
    /** Période par identifiant, null si elle n'existe pas dans ce client et cet établissement. */
    public function find(string $tenantId, string $establishmentId, string $periodId): ?PeriodInfo;

    /**
     * Toutes les périodes de l'année, triées par date de début, puis de l'englobante à l'englobée (année, trimestre, séquence), puis
     * par rang. `parentId` relie une séquence à son trimestre et un trimestre à la période « année » quand elle existe.
     *
     * @return list<PeriodInfo>
     */
    public function ofYear(string $tenantId, string $establishmentId, string $academicYearId): array;
}
