<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts;

/**
 * Sessions d'examen officiel et leurs candidats, en LECTURE (T906) : relance NOT-10 « frais d'examen non constatés à J-15 de la date
 * limite » portée par Fees. Seules les sessions qui ne sont pas closes et dont la date limite d'inscription tombe dans l'intervalle
 * sont rendues.
 */
interface ExamCandidateDirectory
{
    /**
     * @param string $from borne incluse AAAA-MM-JJ
     * @param string $to borne incluse AAAA-MM-JJ
     * @return list<array{session_id: string, exam_code: string, exam_label: string, registration_deadline: string, person_ids: list<string>}>
     */
    public function sessionsWithDeadlineBetween(string $tenantId, string $establishmentId, string $from, string $to): array;
}
