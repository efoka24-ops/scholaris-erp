<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Portals\Application;

use DateInterval;
use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Services\Contracts\StudentServices;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageReader;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\HomeworkReader;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\TeachingAssignments;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Vie scolaire des portails du secondaire (T974), par contrats seulement :
 *  - élève et parent : résultats PUBLIÉS (moyenne, rang, effectif, décision ; jamais une note non publiée), bulletins émis (numéro et
 *    jeton, retenus en cas de blocage de remise pour impayé, RG-FIN-1), absences et retards de l'année, devoirs des deux dernières
 *    semaines du cahier de textes, abonnements aux services (sans montant). Accès restreint (élève majeur sans consentement) : rien ;
 *  - enseignant : ses classes et matières de l'année, couverture du programme par affectation et devoirs donnés récemment.
 * Aucune donnée de santé n'est jamais rendue ici.
 */
final class SchoolLife
{
    public const TEACHER = ['secondary.lesson.log', 'secondary.grades.enter', 'secondary.attendance.take'];
    private const HOMEWORK_DAYS = 14;
    private const HOMEWORK_LIMIT = 30;
    private const REPORT_CARD_TYPES = 'assessment.report_card_types';

    public function __construct(
        private readonly ResultsDirectory $results,
        private readonly DocumentCatalog $documents,
        private readonly SettingsReader $settings,
        private readonly DebtorDirectory $debtors,
        private readonly AttendanceSummaryProvider $attendance,
        private readonly CurrentPeriod $periods,
        private readonly HomeworkReader $homework,
        private readonly StudentServices $services,
        private readonly TeachingAssignments $assignments,
        private readonly CoverageReader $coverage,
        private readonly OrgUnitTree $tree,
        private readonly UserPersonLink $users,
        private readonly Access $access,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function forStudent(string $tenantId, string $establishmentId, PersonSummary $person, EnrollmentInfo $enrollment, bool $full): array
    {
        $base = ['person_id' => $person->id, 'access' => $full ? 'full' : 'restricted'];
        if (!$full) {
            return $base;
        }
        $today = $this->clock->today();
        $results = [];
        foreach ($this->results->publishedFor($tenantId, $person->id) as $result) {
            if ($result->establishmentId !== $establishmentId) {
                continue;
            }
            $results[] = [
                'period_id' => $result->periodId, 'period_label' => $result->periodLabel, 'average' => $result->average, 'rank' => $result->rank,
                'class_size' => $result->classSize, 'mention' => $result->mention, 'decision' => $result->decision,
            ];
        }
        $blocked = $this->debtors->isBlocked($tenantId, $establishmentId, $person->id, 'documents');
        $reportCards = $blocked ? [] : $this->reportCards($tenantId, $establishmentId, $person->id);
        $from = $this->yearStart($tenantId, $establishmentId, $today);
        $summary = $this->attendance->forLearner($tenantId, $establishmentId, $person->id, $from, $today);

        return $base + [
            'results' => $results,
            'report_cards' => ['blocked' => $blocked, 'items' => $reportCards],
            'attendance' => ['from' => $from, 'to' => $today, 'sessions' => $summary->sessions, 'absences' => $summary->absences, 'unjustified_absences' => $summary->unjustifiedAbsences, 'lates' => $summary->lates],
            'homework' => $enrollment->structureId === null ? [] : array_map(
                static fn (array $h): array => ['session_date' => $h['session_date'], 'subject_label' => $h['subject_label'], 'homework' => $h['homework']],
                $this->homework->forClass($tenantId, $establishmentId, $enrollment->structureId, self::shift($today, -self::HOMEWORK_DAYS), self::HOMEWORK_LIMIT),
            ),
            'services' => $this->services->currentFor($tenantId, $establishmentId, $person->id),
        ];
    }

    /** @return array<string, mixed> */
    public function forTeacher(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::TEACHER);
        $tenantId = $principal->tenantId;
        $teacherId = $this->users->personIdForUser($tenantId, $principal->userId)
            ?? throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : contactez l\'établissement.');
        $today = $this->clock->today();
        $year = $this->periods->at($tenantId, $establishmentId, $today)?->academicYearId;
        $classes = [];
        $lines = [];
        foreach ($this->assignments->forTeacher($tenantId, $establishmentId, $teacherId, $year) as $assignment) {
            $classes[$assignment->classId] ??= $this->coverageByAssignment($tenantId, $establishmentId, $assignment->academicYearId, $assignment->classId);
            $structure = $this->tree->structure($tenantId, $establishmentId, $assignment->classId);
            $coverage = $classes[$assignment->classId][$assignment->id] ?? null;
            $lines[] = [
                'assignment_id' => $assignment->id, 'class_id' => $assignment->classId, 'class_name' => $structure?->name, 'group_code' => $assignment->groupCode,
                'subject_label' => $assignment->subjectLabel, 'academic_year_id' => $assignment->academicYearId,
                'coverage' => $coverage === null ? null : ['planned_hours' => $coverage->plannedHours, 'done_hours' => $coverage->doneHours, 'rate' => $coverage->rate, 'last_log_on' => $coverage->lastLogOn],
            ];
        }

        return [
            'teacher_person_id' => $teacherId, 'academic_year_id' => $year, 'assignments' => $lines,
            'homework_given' => $this->homework->givenBy($tenantId, $establishmentId, $teacherId, self::shift($today, -self::HOMEWORK_DAYS), self::HOMEWORK_LIMIT),
        ];
    }

    /**
     * Bulletins valides émis par le socle (types du paramètre `assessment.report_card_types`, à défaut tout type de bulletin) :
     * numéro et jeton de vérification seulement, aucune note.
     *
     * @return list<array{id: string, number: string, type: string, verification_token: string}>
     */
    private function reportCards(string $tenantId, string $establishmentId, string $personId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, self::REPORT_CARD_TYPES, null);
        $types = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
        $out = [];
        foreach ($this->documents->validFor($tenantId, $personId, 100) as $document) {
            if ($types !== [] ? in_array($document->type, $types, true) : str_contains($document->type, 'report')) {
                $out[] = ['id' => $document->id, 'number' => $document->number, 'type' => $document->type, 'verification_token' => $document->verificationToken];
            }
        }

        return $out;
    }

    /** @return array<string, \Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageLine> */
    private function coverageByAssignment(string $tenantId, string $establishmentId, string $yearId, string $classId): array
    {
        $out = [];
        foreach ($this->coverage->coverage($tenantId, $establishmentId, $yearId, $classId) as $line) {
            $out[$line->assignmentId] = $line;
        }

        return $out;
    }

    private function yearStart(string $tenantId, string $establishmentId, string $today): string
    {
        $start = null;
        foreach ($this->periods->allAt($tenantId, $establishmentId, $today) as $period) {
            if ($start === null || $period->startsOn < $start) {
                $start = $period->startsOn;
            }
        }

        return $start ?? self::shift($today, -self::HOMEWORK_DAYS);
    }

    private static function shift(string $date, int $days): string
    {
        $moment = new DateTimeImmutable($date);

        return ($days >= 0 ? $moment->add(new DateInterval('P' . $days . 'D')) : $moment->sub(new DateInterval('P' . -$days . 'D')))->format('Y-m-d');
    }
}
