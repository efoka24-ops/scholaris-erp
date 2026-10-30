<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\NationalExamAttendance;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;

/**
 * Conditions de présentation à l'examen national (EX-EXN-02/03) :
 *  - RG-EXN-1 (bloquant) : inscription validée pour l'année de la session, dans la filière (spécialité) et au niveau de sortie
 *    (dernier niveau, decisions R28) ; aucun candidat externe ;
 *  - assiduité ≥ 70 % (decisions E1, NationalExamAttendance, seuil `higher.national_exam.min_attendance_percent`) ;
 *  - frais d'examen réglés (reçu enregistré, ou session sans frais) ; pièces exigées reçues.
 * Les trois dernières conditions décident du passage « dossier complet » ; seule RG-EXN-1 refuse l'inscription sur la liste.
 */
final class EligibilityChecker
{
    public const RG_EXN_1 = 'RG-EXN-1 : seul un étudiant inscrit (inscription validée) pour l\'année de sortie, dans la spécialité de la session, peut figurer sur la liste ; aucun candidat externe.';

    public function __construct(
        private readonly StudentDirectory $students,
        private readonly AcademicDirectory $academic,
        private readonly NationalExamAttendance $attendance,
    ) {
    }

    /**
     * Inscription qui ouvre droit à la session, null si RG-EXN-1 n'est pas satisfaite.
     *
     * @param array<string, mixed> $session
     */
    public function qualifyingEnrolment(string $tenantId, string $establishmentId, array $session, string $personId): ?EnrolmentInfo
    {
        $level = $this->academic->level($tenantId, $establishmentId, (string) $session['level_id']);
        if ($level === null || !$level->isFinal || $level->programId !== (string) $session['program_id']) {
            return null;
        }
        foreach ($this->students->enrolmentsOf($tenantId, $establishmentId, $personId) as $enrolment) {
            if ($enrolment->isValidated() && $enrolment->academicYearId === (string) $session['academic_year_id']
                && $enrolment->programId === (string) $session['program_id'] && $enrolment->levelId === (string) $session['level_id']) {
                return $enrolment;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $session
     * @throws HttpException 422 (RG-EXN-1)
     */
    public function requireQualifying(string $tenantId, string $establishmentId, array $session, string $personId): EnrolmentInfo
    {
        return $this->qualifyingEnrolment($tenantId, $establishmentId, $session, $personId)
            ?? throw new HttpException(422, 'Candidat non admissible', self::RG_EXN_1, 'https://api.scholaris.cm/errors/national-exam-not-eligible', ['person_id' => [self::RG_EXN_1]]);
    }

    /**
     * @param array<string, mixed> $session
     * @return array{presence_percent: float, threshold_percent: float, attendance_ok: bool, sessions: int, unjustified: int}
     */
    public function attendance(string $tenantId, string $establishmentId, array $session, string $personId): array
    {
        $presence = $this->attendance->presenceOf($tenantId, $establishmentId, $personId, (string) $session['attendance_from'], (string) $session['attendance_to']);

        return [
            'presence_percent' => round($presence['presence_percent'], 2), 'threshold_percent' => $presence['threshold_percent'],
            'attendance_ok' => $presence['eligible'], 'sessions' => $presence['sessions'], 'unjustified' => $presence['unjustified'],
        ];
    }

    /**
     * Bilan complet pour l'écran (aucune écriture).
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function report(string $tenantId, string $establishmentId, array $session, string $personId): array
    {
        $enrolment = $this->qualifyingEnrolment($tenantId, $establishmentId, $session, $personId);
        $report = ['person_id' => $personId, 'rg_exn_1' => $enrolment !== null, 'enrolment_id' => $enrolment?->id, 'reasons' => []];
        if ($enrolment === null) {
            $report['reasons'][] = self::RG_EXN_1;

            return $report + ['attendance' => null];
        }
        $attendance = $this->attendance($tenantId, $establishmentId, $session, $personId);
        if (!$attendance['attendance_ok']) {
            $report['reasons'][] = sprintf('Assiduité %.2f %% inférieure au seuil de %.0f %%.', $attendance['presence_percent'], $attendance['threshold_percent']);
        }

        return $report + ['attendance' => $attendance];
    }
}
