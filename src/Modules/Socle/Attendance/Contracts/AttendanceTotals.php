<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/**
 * Synthèses d'assiduité de tous les apprenants d'un établissement en une seule lecture (tableaux de bord). Contrat
 * distinct d'AttendanceSummaryProvider pour ne pas imposer de nouvelle méthode à ses implémentations existantes.
 */
interface AttendanceTotals
{
    /**
     * Bornes incluses, AAAA-MM-JJ. Séances validées seulement.
     *
     * @return array<string, AttendanceSummary> par identifiant d'apprenant ayant au moins une séance
     */
    public function byLearner(string $tenantId, string $establishmentId, string $from, string $to): array;
}
