<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/**
 * Assiduité par élément constitutif ou unité d'enseignement (D19), et non plus par personne sur une plage de dates :
 * base de RG-PRE-1 (absences non justifiées d'un EC au-delà de 30 % : non autorisé à composer cet EC).
 *
 * Dénominateur selon le paramètre `attendance.base` (D20) : `held` (défaut) = séances de l'EC dont l'appel est validé ;
 * `planned` = séances programmées de l'EC dans l'emploi du temps sur [from, to] pour les groupes de l'apprenant (annulées
 * exclues), jamais moins que les séances validées ; sans bornes, `planned` se comporte comme `held`.
 * Bornes incluses, AAAA-MM-JJ, nulles = sans limite. Séances validées seulement.
 *
 * Contrat distinct d'AttendanceSummaryProvider pour ne pas imposer de nouvelles méthodes à ses implémentations existantes.
 */
interface UnitAttendanceSummaryProvider
{
    public function forLearnerByUnit(string $tenantId, string $establishmentId, string $learnerId, string $courseUnitId, ?string $from = null, ?string $to = null): AttendanceSummary;

    /**
     * Apprenants d'un EC dont le taux d'absences non justifiées dépasse strictement le seuil (en %).
     *
     * @return array<string, AttendanceSummary> par identifiant d'apprenant
     */
    public function learnersAbove(string $tenantId, string $establishmentId, string $courseUnitId, float $thresholdPercent, ?string $from = null, ?string $to = null): array;

    /**
     * EC d'un apprenant dont le taux d'absences non justifiées dépasse strictement le seuil (en %).
     *
     * @return array<string, AttendanceSummary> par identifiant d'EC
     */
    public function unitsAbove(string $tenantId, string $establishmentId, string $learnerId, float $thresholdPercent, ?string $from = null, ?string $to = null): array;
}
