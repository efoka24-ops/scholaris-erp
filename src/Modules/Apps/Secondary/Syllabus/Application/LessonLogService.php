<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\AssignmentInfo;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\TeachingAssignments;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/**
 * Cahier de textes par séance (EX-CTX-02, API-06 `POST /secondary/lesson-logs`, T862) : l'enseignant consigne la leçon traitée,
 * les travaux donnés et les heures ; la séance est signée électroniquement (empreinte SHA-256 du contenu, auteur, instant).
 * Hors ligne : `client_uuid` rend l'envoi idempotent (un rejeu rend la séance déjà enregistrée, 200). Une séance d'une classe ou
 * d'une matière où l'enseignant n'est pas affecté est refusée (403) et journalisée. Une séance signée ne se modifie plus.
 */
final class LessonLogService
{
    public const TABLE = 'se_lesson_logs';
    private const MAX_HOURS = 12;

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly TeachingAssignments $assignments,
        private readonly ClassProfiles $classes,
        private readonly ProgressionService $progressions,
        private readonly UserPersonLink $links,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $body client_uuid, class_id, subject_ref, session_date, hours, content, homework?, lesson_id?, entry_id?
     * @return array{0: array<string, mixed>, 1: bool} séance, vrai si elle vient d'être créée (faux : rejeu hors ligne)
     */
    public function create(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, SyllabusAccess::LOG);
        $in = new Input($body);
        $uuid = (string) $in->custom('client_uuid', static function (mixed $v): string {
            if (!is_string($v) || preg_match('/^[A-Za-z0-9-]{8,40}$/', $v) !== 1) {
                throw new \InvalidArgumentException('Identifiant de 8 à 40 caractères (lettres, chiffres, tirets) attendu.');
            }

            return $v;
        });
        $classId = (string) $in->reference('class_id');
        $subjectRef = (string) $in->reference('subject_ref');
        $date = (string) $in->date('session_date');
        $hours = (float) $in->number('hours', 0.25, self::MAX_HOURS, 0.25);
        $content = (string) $in->string('content', 5000);
        $homework = $in->string('homework', 5000, false, true);
        $lessonId = $in->reference('lesson_id', false, true);
        $entryId = $in->reference('entry_id', false, true);
        $in->done();
        $person = $this->links->personIdForUser($tenantId, $principal->userId);
        $replay = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['client_uuid' => $uuid], 'id', 1)[0] ?? null;
        if ($replay !== null) {
            if ($replay['signed_by'] !== $principal->userId) {
                throw HttpException::conflict('Cet identifiant de séance est déjà utilisé.');
            }

            return [self::log($replay), false];
        }
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'se_lesson_log', $classId);
        $assignment = $person === null ? null : $this->assignmentOf($tenantId, $establishmentId, $classId, $subjectRef, $person);
        if ($assignment === null) {
            $this->access->audit($principal, 'secondary.lesson.denied', 'se_lesson_log', $classId, null, ['class_id' => $classId, 'subject_ref' => $subjectRef, 'person_id' => $person]);

            throw HttpException::forbidden('Vous n\'êtes pas affecté à cette classe pour cette matière.');
        }
        $errors = [];
        if ($date > $this->clock->today()) {
            $errors['session_date'] = ['Une séance future ne peut pas être consignée.'];
        }
        $slotId = null;
        if ($entryId !== null) {
            $entry = $this->assignments->entry($tenantId, $establishmentId, $entryId);
            if ($entry === null || $entry->assignmentId !== $assignment->id || !$entry->holdsOn($date)) {
                $errors['entry_id'] = ['Ce créneau ne relève pas de cette affectation à cette date.'];
            } else {
                $slotId = $entry->slotId;
            }
        }
        if ($lessonId !== null && !$this->lessonBelongs($tenantId, $establishmentId, $assignment, $lessonId)) {
            $errors['lesson_id'] = ['Leçon absente de la progression de cette matière pour ce niveau.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $now = $this->clock->now();
        $signature = hash('sha256', json_encode([$uuid, $classId, $subjectRef, $date, $hours, $content, $homework, $lessonId, $person, $principal->userId, $now], JSON_THROW_ON_ERROR));
        $row = [
            'id' => (string) new Ulid(), 'academic_year_id' => $assignment->academicYearId, 'structure_id' => $classId, 'assignment_id' => $assignment->id,
            'se_subject_id' => $subjectRef, 'subject_id' => $assignment->subjectId, 'subject_label' => $assignment->subjectLabel, 'teacher_person_id' => $assignment->teacherPersonId,
            'entry_id' => $entryId, 'slot_id' => $slotId, 'session_date' => $date, 'lesson_id' => $lessonId, 'content' => $content, 'homework' => $homework,
            'hours' => $hours, 'signed_by' => $principal->userId, 'signed_at' => $now, 'signature_hash' => $signature, 'client_uuid' => $uuid, 'created_at' => $now,
        ];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);
        $this->access->audit($principal, 'secondary.lesson.logged', 'se_lesson_log', (string) $row['id'], null, ['class_id' => $classId, 'subject_ref' => $subjectRef, 'session_date' => $date, 'hours' => $hours]);

        return [self::log($row), true];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function log(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'class_id' => (string) $row['structure_id'],
            'assignment_id' => (string) $row['assignment_id'], 'subject_ref' => (string) $row['se_subject_id'], 'subject_label' => (string) $row['subject_label'],
            'teacher_person_id' => (string) $row['teacher_person_id'], 'entry_id' => $row['entry_id'] === null ? null : (string) $row['entry_id'],
            'session_date' => (string) $row['session_date'], 'lesson_id' => $row['lesson_id'] === null ? null : (string) $row['lesson_id'],
            'content' => (string) $row['content'], 'homework' => $row['homework'] === null ? null : (string) $row['homework'], 'hours' => (float) $row['hours'],
            'signed_by' => (string) $row['signed_by'], 'signed_at' => (string) $row['signed_at'], 'signature_hash' => (string) $row['signature_hash'],
            'client_uuid' => (string) $row['client_uuid'],
        ];
    }

    private function assignmentOf(string $tenantId, string $establishmentId, string $classId, string $subjectRef, string $person): ?AssignmentInfo
    {
        foreach ($this->assignments->forTeacher($tenantId, $establishmentId, $person) as $assignment) {
            if ($assignment->classId === $classId && $assignment->localSubjectId === $subjectRef) {
                return $assignment;
            }
        }

        return null;
    }

    private function lessonBelongs(string $tenantId, string $establishmentId, AssignmentInfo $assignment, string $lessonId): bool
    {
        $class = $this->classes->find($tenantId, $establishmentId, $assignment->classId);
        $lesson = $this->store->find(ProgressionService::LESSONS, $tenantId, $establishmentId, $lessonId);
        if ($class === null || $lesson === null) {
            return false;
        }
        $progression = $this->progressions->forClass($tenantId, $establishmentId, $assignment->academicYearId, $assignment->localSubjectId, $class->levelCode, $class->streamId);

        return $progression !== null && $progression['id'] === $lesson['progression_id'];
    }
}
