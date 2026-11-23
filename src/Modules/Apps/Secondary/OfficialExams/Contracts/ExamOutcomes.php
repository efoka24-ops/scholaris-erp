<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts;

/**
 * Résultats agrégés des examens officiels d'une année (contrat de LECTURE interne, fourni par OfficialExams, ajouté pour les
 * tableaux de bord et le rapport annuel E4-A) : par session, candidats, résultats importés et admis selon les codes de réussite de la
 * session. Aucun nom ni matricule. Borné au client et à l'établissement.
 */
interface ExamOutcomes
{
    /** @return list<array{session_id: string, exam_code: string, exam_label: string, class_structure_id: string, candidates: int, results: int, passed: int}> */
    public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array;
}
