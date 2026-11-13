<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Contracts;

/**
 * Avancement des feuilles de compétences d'une classe, offert aux autres sous-modules Primary (tableau de bord, clôture).
 * Toujours borné au client et à l'établissement ; aucune donnée nominative.
 */
interface SheetProgress
{
    /** @return array<string, int> nombre d'évaluations saisies par période de la classe */
    public function marksByPeriod(string $tenantId, string $establishmentId, string $classId): array;

    /** @return array<string, string> état de la feuille (open, locked, published) par période ; une période sans ligne est « open » */
    public function statesByPeriod(string $tenantId, string $establishmentId, string $classId): array;
}
