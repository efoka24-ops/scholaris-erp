<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Période en cours d'un établissement à une date donnée (trimestre ou semestre ; la séquence si l'établissement n'a que des
 * séquences). Fourni par Organisation, consommé par Identity (tuiles de /me/contexts) et les applications.
 */
interface CurrentPeriod
{
    /**
     * @param string $date jour au format AAAA-MM-JJ (ou ISO 8601 complet : seuls les dix premiers caractères comptent)
     * @return PeriodInfo|null null quand aucune période ne couvre ce jour (jamais de période inventée)
     */
    public function at(string $tenantId, string $establishmentId, string $date): ?PeriodInfo;

    /**
     * Toutes les périodes qui couvrent ce jour (la séquence, le trimestre qui la contient et la période « année », par
     * exemple), triées par date de début puis par rang ; à égalité, l'englobante d'abord (année, trimestre, séquence).
     * at() n'en retient jamais la période « année ».
     *
     * @param string $date jour au format AAAA-MM-JJ (ou ISO 8601 complet : seuls les dix premiers caractères comptent)
     * @return list<PeriodInfo> liste vide quand aucune période ne couvre ce jour ou que la date est invalide
     */
    public function allAt(string $tenantId, string $establishmentId, string $date): array;
}
