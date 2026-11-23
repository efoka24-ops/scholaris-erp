<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts;

/**
 * État du matricule de plusieurs élèves en une lecture (contrat de LECTURE interne, fourni par CarteScolaire, ajouté pour l'objectif
 * O3 des tableaux de bord E4-A) : jamais le matricule lui-même, seulement son état. Borné au client et à l'établissement.
 */
interface NationalIdCoverage
{
    /**
     * @param list<string> $personIds
     * @return array<string, string> état (`recorded`, `mismatch`, `pending`) par personne ; un élève sans ligne est absent (en attente)
     */
    public function statusesOf(string $tenantId, string $establishmentId, array $personIds): array;
}
