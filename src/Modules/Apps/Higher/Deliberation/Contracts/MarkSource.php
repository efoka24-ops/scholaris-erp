<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Contracts;

/**
 * Point d'extension : source des notes d'un groupe pour une période et une session, au format du moteur du socle (unités faites
 * d'EC portant leurs épreuves). Sert à la simulation avant le jury (API-13, EX-DEL-02), à la liste des convoqués au rattrapage
 * (EX-DEL-04) et au contrôle « toutes les notes verrouillées » (RG-DEL-1).
 *
 * Enregistrement : entrée de liste du conteneur `higher.deliberation.mark_sources.<module>` => [classe ou instance, …] ; la première
 * source qui répond une feuille non nulle l'emporte. À fournir par le module qui détient les notes (Higher\Grades, ou un contrat
 * de lecture des notes du socle Assessment : voir le rapport L1).
 */
interface MarkSource
{
    public const PREFIX = 'higher.deliberation.mark_sources.';

    /**
     * @param string $periodId semestre de la maquette noté « S<n> » (S1, S2… numérotation de la filière)
     * @param string|null $academicYearId année du jury ; null = la plus récente qui a des notes pour ce groupe
     * @return MarkSheet|null null si cette source ne connaît pas ce groupe
     */
    public function sheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): ?MarkSheet;
}
