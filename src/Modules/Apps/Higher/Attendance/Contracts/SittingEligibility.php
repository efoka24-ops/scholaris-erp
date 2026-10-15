<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Contracts;

/**
 * Autorisation de composer un élément constitutif (RG-PRE-1) pour les autres sous-modules Higher (Grades : code NA et note 0 ;
 * Teaching : convocations aux examens DOC-13 ; Deliberation). Un étudiant dont les absences non justifiées d'un EC dépassent le seuil
 * (`attendance.max_unjustified_percent`, 30 % par défaut ; base `attendance.base`) n'est pas autorisé à composer cet EC, sauf levée
 * tracée (dérogation ou épreuve de remplacement après absence justifiée). Borné au client et à l'établissement.
 */
interface SittingEligibility
{
    public function isBarred(string $tenantId, string $establishmentId, string $personId, string $elementId): bool;

    /**
     * @param list<string> $personIds étudiants à examiner (ceux d'un groupe)
     * @return list<string> identifiants des étudiants non autorisés à composer l'EC
     */
    public function barredAmong(string $tenantId, string $establishmentId, string $elementId, array $personIds): array;
}
