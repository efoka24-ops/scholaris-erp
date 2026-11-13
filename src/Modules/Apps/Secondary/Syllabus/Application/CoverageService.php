<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageLine;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageReader;
use Scholaris\Modules\Apps\Secondary\Syllabus\Domain\SyllabusDefaults;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\TeachingAssignments;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Couverture des programmes (EX-CTX-03, T863) : par affectation, heures faites (séances signées) / heures prévues par la
 * progression, leçons traitées, heures attendues à la date si le début d'année est donné ; visas du censeur et de l'animateur
 * (EX-CTX-04) ; cahier de textes de la classe DOC-25 ; rapport de couverture DOC-26 ; rappel NOT-12 à l'enseignant dont le cahier
 * n'est pas rempli depuis N jours (paramètre de l'établissement, valeur de départ à confirmer).
 */
final class CoverageService implements CoverageReader
{
    public const VISAS = 'se_lesson_visas';
    public const NOTICES = 'se_syllabus_notices';
    public const TEMPLATE_NOT12 = 'sec.not12.lesson_log_missing';
    private const ROUND = 4;

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly SyllabusAccess $syllabus,
        private readonly TeachingAssignments $assignments,
        private readonly ClassProfiles $classes,
        private readonly ProgressionService $progressions,
        private readonly PersonDirectory $people,
        private readonly Messenger $messenger,
        private readonly SettingsReader $settings,
        private readonly SecondaryClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function coverage(string $tenantId, string $establishmentId, string $academicYearId, string $classId): array
    {
        $class = $this->classes->find($tenantId, $establishmentId, $classId);
        if ($class === null) {
            return [];
        }
        $lines = [];
        foreach ($this->assignments->forClass($tenantId, $establishmentId, $classId, $academicYearId) as $assignment) {
            $progression = $this->progressions->forClass($tenantId, $establishmentId, $academicYearId, $assignment->localSubjectId, $class->levelCode, $class->streamId);
            $logs = $this->store->select(LessonLogService::TABLE, $tenantId, $establishmentId, ['assignment_id' => $assignment->id], 'session_date', 5000);
            $done = round(array_sum(array_map(static fn (array $l): float => (float) $l['hours'], $logs)), 2);
            $planned = $progression === null ? null : (float) $progression['total_hours'];
            $lessons = $progression === null ? [] : $this->progressions->lessons($tenantId, $establishmentId, (string) $progression['id']);
            $treated = array_unique(array_filter(array_map(static fn (array $l): ?string => $l['lesson_id'] === null ? null : (string) $l['lesson_id'], $logs)));
            $last = $logs === [] ? null : (string) end($logs)['session_date'];
            $lines[] = new CoverageLine(
                $assignment->id,
                $classId,
                $assignment->groupCode,
                $assignment->localSubjectId,
                $assignment->subjectLabel,
                $assignment->teacherPersonId,
                $planned,
                $done,
                $planned === null || $planned <= 0 ? null : round($done / $planned, self::ROUND),
                count($lessons),
                count(array_intersect($treated, array_column($lessons, 'id'))),
                $last,
            );
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function classCoverage(Principal $principal, string $establishmentId, string $academicYearId, string $classId, ?string $yearStart): array
    {
        [$perimeter, $inspector] = $this->syllabus->readable($principal, $establishmentId);
        $this->access->assertReadableClass($perimeter, $classId);
        $this->syllabus->logInspection($principal, $inspector, 'se_coverage', $classId);

        return $this->classBlock($principal->tenantId, $establishmentId, $academicYearId, $classId, $yearStart);
    }

    /**
     * DOC-26 : rapport de couverture des classes du périmètre (document à la demande, sans numéro).
     *
     * @return array<string, mixed>
     */
    public function report(Principal $principal, string $establishmentId, string $academicYearId, ?string $yearStart): array
    {
        $tenantId = $principal->tenantId;
        [$perimeter, $inspector] = $this->syllabus->readable($principal, $establishmentId);
        $blocks = [];
        [$planned, $done] = [0.0, 0.0];
        foreach ($this->classes->forYear($tenantId, $establishmentId, $academicYearId) as $class) {
            if (!$perimeter->coversClass($class->structureId)) {
                continue;
            }
            $block = $this->classBlock($tenantId, $establishmentId, $academicYearId, $class->structureId, $yearStart);
            foreach ($block['lines'] as $line) {
                if ($line['planned_hours'] !== null) {
                    $planned += $line['planned_hours'];
                    $done += $line['done_hours'];
                }
            }
            $blocks[] = $block;
        }
        $this->syllabus->logInspection($principal, $inspector, 'se_coverage_report', $academicYearId);

        return [
            'doc' => 'DOC-26', 'title' => 'Rapport de couverture des programmes', 'title_en' => 'Syllabus coverage report', 'academic_year_id' => $academicYearId,
            'classes' => $blocks, 'planned_hours' => round($planned, 2), 'done_hours' => round($done, 2), 'rate' => $planned > 0 ? round($done / $planned, self::ROUND) : null,
            'generated_at' => $this->clock->now(),
        ];
    }

    /**
     * DOC-25 : cahier de textes de la classe (séances signées, visas), à la demande.
     *
     * @return array<string, mixed>
     */
    public function logbook(Principal $principal, string $establishmentId, string $academicYearId, string $classId, ?string $subjectRef): array
    {
        $tenantId = $principal->tenantId;
        [$perimeter, $inspector] = $this->syllabus->readable($principal, $establishmentId);
        $this->access->assertReadableClass($perimeter, $classId);
        $where = ['academic_year_id' => $academicYearId, 'structure_id' => $classId] + ($subjectRef === null ? [] : ['se_subject_id' => $subjectRef]);
        $logs = $this->store->select(LessonLogService::TABLE, $tenantId, $establishmentId, $where, 'session_date, subject_label, id', 5000);
        $names = $this->names($tenantId, array_map(static fn (array $l): string => (string) $l['teacher_person_id'], $logs));
        $entries = array_map(static fn (array $l): array => LessonLogService::log($l) + ['teacher_name' => $names[(string) $l['teacher_person_id']] ?? ''], $logs);
        $visas = array_map(static fn (array $v): array => self::visa($v), $this->store->select(self::VISAS, $tenantId, $establishmentId, $where, 'visa_on, id', 1000));
        $this->syllabus->logInspection($principal, $inspector, 'se_lesson_logbook', $classId);
        $class = $this->classes->find($tenantId, $establishmentId, $classId);

        return [
            'doc' => 'DOC-25', 'title' => 'Cahier de textes de la classe', 'title_en' => 'Class logbook', 'academic_year_id' => $academicYearId, 'class_id' => $classId,
            'class_label' => $class === null ? $classId : $class->label, 'entries' => $entries, 'visas' => $visas, 'generated_at' => $this->clock->now(),
        ];
    }

    /**
     * Visa du cahier de textes d'une matière dans une classe (censeur : `censor` ; animateur : `head_of_dept`).
     *
     * @param array<string, mixed> $body academic_year_id, class_id, subject_ref, remark?
     * @return array<string, mixed>
     */
    public function addVisa(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $role = null;
        $perimeter = new Perimeter($tenantId, $establishmentId, false);
        foreach (SyllabusAccess::VISA_ROLES as $permission => $candidate) {
            $own = $this->access->perimeter($principal, $establishmentId, [$permission]);
            if (!$own->isEmpty()) {
                [$role, $perimeter] = [$candidate, $own];
                break;
            }
        }
        if ($role === null) {
            $this->access->require($principal, $establishmentId, array_keys(SyllabusAccess::VISA_ROLES));
        }
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $classId = (string) $in->reference('class_id');
        $subjectRef = (string) $in->reference('subject_ref');
        $remark = $in->string('remark', 500, false, true);
        $in->done();
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'se_lesson_visa', $classId);
        $taught = false;
        foreach ($this->assignments->forClass($tenantId, $establishmentId, $classId, $year) as $assignment) {
            $taught = $taught || $assignment->localSubjectId === $subjectRef;
        }
        if (!$taught) {
            throw HttpException::validation(['subject_ref' => ['Aucun enseignant affecté à cette matière dans cette classe pour l\'année.']]);
        }
        $row = [
            'id' => (string) new Ulid(), 'academic_year_id' => $year, 'structure_id' => $classId, 'se_subject_id' => $subjectRef, 'visa_role' => (string) $role,
            'visa_on' => $this->clock->today(), 'remark' => $remark, 'visa_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ];
        $this->store->insert(self::VISAS, $tenantId, $establishmentId, $row);
        $this->access->audit($principal, 'secondary.syllabus.visa', 'se_lesson_visa', (string) $row['id'], null, $row);

        return self::visa($row);
    }

    /**
     * NOT-12 : rappel aux enseignants dont le cahier de textes n'est pas rempli depuis N jours (depuis la dernière séance, ou depuis
     * le début de leur premier créneau). Une fois par affectation et par jour.
     *
     * @param array<string, mixed> $body academic_year_id
     * @return array<string, mixed>
     */
    public function remind(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, SyllabusAccess::REMIND);
        $this->access->assertWhole($principal, $perimeter, 'se_syllabus_notice');
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $in->done();
        $limit = $this->reminderDays($tenantId, $establishmentId);
        $today = $this->clock->today();
        $late = [];
        $notified = 0;
        foreach ($this->assignments->forYear($tenantId, $establishmentId, $year) as $assignment) {
            $entries = $this->assignments->entriesOf($tenantId, $establishmentId, $assignment->id);
            if ($entries === []) {
                continue;
            }
            $logs = $this->store->select(LessonLogService::TABLE, $tenantId, $establishmentId, ['assignment_id' => $assignment->id], 'session_date DESC', 1);
            $since = $logs === [] ? min(array_map(static fn ($e): string => $e->validFrom, $entries)) : (string) $logs[0]['session_date'];
            if ($since > $today) {
                continue;
            }
            $days = (int) (new \DateTimeImmutable($since))->diff(new \DateTimeImmutable($today))->days;
            if ($days < $limit) {
                continue;
            }
            $class = $this->classes->find($tenantId, $establishmentId, $assignment->classId);
            $className = $class === null ? $assignment->classId : $class->label;
            $late[] = ['assignment_id' => $assignment->id, 'class_id' => $assignment->classId, 'class_label' => $className, 'subject_label' => $assignment->subjectLabel, 'teacher_person_id' => $assignment->teacherPersonId, 'since' => $since, 'days' => $days];
            $notified += $this->notify($tenantId, $establishmentId, $assignment->id, $assignment->teacherPersonId, $today, ['offset' => $days, 'subject' => $assignment->subjectLabel, 'class_name' => $className]);
        }
        $this->access->audit($principal, 'secondary.syllabus.reminders', 'se_syllabus_notice', $year, null, ['late' => count($late), 'notified' => $notified]);

        return ['academic_year_id' => $year, 'reminder_days' => $limit, 'confirmation' => SyllabusDefaults::CONFIRMATION, 'late' => $late, 'notified' => $notified];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function visa(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'class_id' => (string) $row['structure_id'],
            'subject_ref' => (string) $row['se_subject_id'], 'visa_role' => (string) $row['visa_role'], 'visa_on' => (string) $row['visa_on'],
            'remark' => $row['remark'] === null ? null : (string) $row['remark'], 'visa_by' => (string) $row['visa_by'], 'created_at' => (string) $row['created_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function classBlock(string $tenantId, string $establishmentId, string $academicYearId, string $classId, ?string $yearStart): array
    {
        $class = $this->classes->find($tenantId, $establishmentId, $classId);
        $week = null;
        if ($yearStart !== null && $yearStart <= $this->clock->today()) {
            $week = intdiv((int) (new \DateTimeImmutable($yearStart))->diff(new \DateTimeImmutable($this->clock->today()))->days, 7) + 1;
        }
        $lines = [];
        foreach ($this->coverage($tenantId, $establishmentId, $academicYearId, $classId) as $line) {
            $expected = null;
            if ($week !== null && $class !== null) {
                $progression = $this->progressions->forClass($tenantId, $establishmentId, $academicYearId, $line->localSubjectId, $class->levelCode, $class->streamId);
                if ($progression !== null) {
                    $expected = 0.0;
                    foreach ($this->progressions->lessons($tenantId, $establishmentId, (string) $progression['id']) as $lesson) {
                        $expected += $lesson['planned_week'] <= $week ? $lesson['planned_hours'] : 0.0;
                    }
                    $expected = round($expected, 2);
                }
            }
            $lines[] = [
                'assignment_id' => $line->assignmentId, 'group_code' => $line->groupCode, 'subject_ref' => $line->localSubjectId, 'subject_label' => $line->subjectLabel,
                'teacher_person_id' => $line->teacherPersonId, 'planned_hours' => $line->plannedHours, 'done_hours' => $line->doneHours, 'rate' => $line->rate,
                'rate_percent' => $line->rate === null ? null : round($line->rate * 100, 2), 'expected_hours_to_date' => $expected,
                'lessons_total' => $line->lessonsTotal, 'lessons_done' => $line->lessonsDone, 'last_log_on' => $line->lastLogOn,
                'visas' => $this->store->count(self::VISAS, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId, 'structure_id' => $classId, 'se_subject_id' => $line->localSubjectId]),
            ];
        }

        return ['class_id' => $classId, 'class_label' => $class === null ? $classId : $class->label, 'academic_year_id' => $academicYearId, 'week' => $week, 'lines' => $lines];
    }

    /** @param array<string, scalar> $variables */
    private function notify(string $tenantId, string $establishmentId, string $assignmentId, string $personId, string $today, array $variables): int
    {
        $where = ['assignment_id' => $assignmentId, 'notice_on' => $today, 'recipient_person_id' => $personId];
        if ($this->store->count(self::NOTICES, $tenantId, $establishmentId, $where) > 0) {
            return 0;
        }
        try {
            $result = $this->messenger->send($tenantId, $establishmentId, $personId, self::TEMPLATE_NOT12, $variables, ['push']);
        } catch (Throwable $e) {
            $this->logger->warning('Notification NOT-12 non envoyée : ' . $e->getMessage(), ['assignment' => $assignmentId]);

            return 0;
        }
        $this->store->insert(self::NOTICES, $tenantId, $establishmentId, $where + ['id' => (string) new Ulid(), 'delivery_status' => $result->status, 'created_at' => $this->clock->now()]);

        return 1;
    }

    private function reminderDays(string $tenantId, string $establishmentId): int
    {
        $value = $this->settings->value($tenantId, $establishmentId, SyllabusDefaults::REMINDER_DAYS_KEY, SyllabusDefaults::REMINDER_DAYS);

        return is_int($value) && $value > 0 ? $value : SyllabusDefaults::REMINDER_DAYS;
    }

    /**
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function names(string $tenantId, array $ids): array
    {
        $names = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $person = $this->people->find($tenantId, $id);
            $names[$id] = $person === null ? $id : $person->fullName();
        }

        return $names;
    }
}
