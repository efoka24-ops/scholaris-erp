<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Contracts;

/**
 * Redoublants parmi plusieurs élèves en une lecture (contrat de LECTURE interne, fourni par Enrolment, ajouté pour le rapport de
 * rentrée DOC-32 des tableaux de bord E4-A). Borné au client et à l'établissement.
 */
interface RepeaterRoster
{
    /**
     * @param list<string> $personIds
     * @return list<string> identifiants des personnes marquées redoublantes
     */
    public function repeatersAmong(string $tenantId, string $establishmentId, array $personIds): array;
}
