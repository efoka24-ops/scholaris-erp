<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\SittingEligibility;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Teaching\Documents\TeachingDocumentTypes;
use Scholaris\Modules\Apps\Higher\Teaching\Domain\TimetableOverlaps;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;

/**
 * Planning des examens (EX-EDT-06, Should) par le service des examens : épreuve d'un EC pour un groupe, date, heures, salle,
 * surveillants, sujet ; conflits de salle, de groupe et de surveillant le même jour ; convocations DOC-13 aux seuls étudiants autorisés
 * à composer (RG-PRE-1) ; liste d'émargement DOC-08 remise aux surveillants.
 */
final class ExamScheduleService
{
    public const TABLE = 'hi_exam_schedules';
    public const SESSIONS = ['normal', 'retake', 'replacement'];

    public function __construct(
        private readonly TeachingStore $store,
        private readonly TeachingAccess $access,
        private readonly ElementResolver $elements,
        private readonly StudentDirectory $students,
        private readonly SittingEligibility $eligibility,
        private readonly HigherDocumentIssuer $documents,
        private readonly TeachingClock $clock,
    ) {
    }

    /**
     * @param array{curriculum_id: string, element_id: string, group_id: string, exam_session: string, exam_date: string, start_time: string, end_time: string, room_id: ?string, supervisors: list<string>, paper_ref: ?string} $data
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $place = $this->elements->locate($tenantId, $establishmentId, $data['curriculum_id'], $data['element_id'], $data['group_id']);
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::EXAM_MANAGE);
        $this->access->assertWritable($principal, $perimeter, $place, 'hi_exam_schedule', $data['element_id']);
        if (TimetableOverlaps::minutes($data['end_time']) <= TimetableOverlaps::minutes($data['start_time'])) {
            throw HttpException::validation(['end_time' => ['L\'heure de fin doit suivre l\'heure de début.']]);
        }
        $this->assertNoConflict($tenantId, $establishmentId, $data, $data['group_id']);
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(), 'school_id' => $place['school_id'], 'department_id' => $place['department_id'], 'program_id' => $place['program_id'],
            'level_id' => $place['level_id'], 'group_id' => $place['group_id'], 'academic_year_id' => $place['academic_year_id'], 'element_id' => $data['element_id'],
            'element_title' => $place['element_title'], 'exam_session' => $data['exam_session'], 'exam_date' => $data['exam_date'], 'start_time' => $data['start_time'],
            'end_time' => $data['end_time'], 'room_id' => $data['room_id'], 'supervisors' => json_encode($data['supervisors'], JSON_THROW_ON_ERROR),
            'paper_ref' => $data['paper_ref'], 'status' => 'planned', 'convocations_count' => 0, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.exam.scheduled', 'hi_exam_schedule', $row['id'], null, $row);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $row['id']);
    }

    /** @return array<string, mixed> */
    public function setStatus(Principal $principal, string $establishmentId, string $id, string $status): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id);
        if ($row['status'] === 'cancelled') {
            throw HttpException::conflict('Épreuve annulée.');
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => $status, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.exam.' . $status, 'hi_exam_schedule', $id, ['status' => $row['status']], ['status' => $status]);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /**
     * Convocations DOC-13 des étudiants inscrits du groupe autorisés à composer ; les non autorisés sont listés à part (RG-PRE-1).
     *
     * @return array{issued: int, barred: list<string>, documents: list<array<string, mixed>>}
     */
    public function convocations(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id);
        if ($row['status'] !== 'published') {
            throw HttpException::conflict('Publiez l\'épreuve avant d\'émettre les convocations.');
        }
        $persons = array_map(static fn ($e): string => $e->personId, $this->students->enrolledIn($tenantId, $establishmentId, (string) $row['group_id'], (string) $row['academic_year_id']));
        $barred = $this->eligibility->barredAmong($tenantId, $establishmentId, (string) $row['element_id'], $persons);
        $documents = [];
        foreach (array_diff($persons, $barred) as $personId) {
            $documents[] = $this->documents->issueFor($tenantId, $establishmentId, TeachingDocumentTypes::EXAM_NOTICE, $personId, (string) $row['school_id'], (string) $row['program_id'], (string) $row['level_id'], [
                'exam_subject' => (string) $row['element_title'], 'exam_date' => (string) $row['exam_date'], 'exam_time' => $row['start_time'] . '-' . $row['end_time'],
                'exam_room' => (string) ($row['room_id'] ?? '-'), 'exam_session' => (string) $row['exam_session'],
            ], $principal->userId);
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['convocations_count' => count($documents), 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.exam.convocations', 'hi_exam_schedule', $id, null, ['issued' => count($documents), 'barred' => $barred]);

        return ['issued' => count($documents), 'barred' => $barred, 'documents' => $documents];
    }

    /**
     * Liste d'émargement d'examen (DOC-08), remise au premier surveillant.
     *
     * @return array<string, mixed>
     */
    public function attendanceSheet(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id);
        $supervisors = json_decode((string) $row['supervisors'], true);
        if (!is_array($supervisors) || $supervisors === []) {
            throw HttpException::conflict('Désignez au moins un surveillant avant d\'émettre la liste d\'émargement.');
        }
        $persons = array_map(static fn ($e): string => $e->personId, $this->students->enrolledIn($tenantId, $establishmentId, (string) $row['group_id'], (string) $row['academic_year_id']));
        $barred = $this->eligibility->barredAmong($tenantId, $establishmentId, (string) $row['element_id'], $persons);

        return $this->documents->issueFor($tenantId, $establishmentId, TeachingDocumentTypes::EXAM_SHEET, (string) $supervisors[0], (string) $row['school_id'], (string) $row['program_id'], (string) $row['level_id'], [
            'exam_subject' => (string) $row['element_title'], 'exam_date' => (string) $row['exam_date'], 'exam_time' => $row['start_time'] . '-' . $row['end_time'],
            'exam_room' => (string) ($row['room_id'] ?? '-'), 'candidates_count' => count($persons) - count($barred), 'barred_count' => count($barred),
        ], $principal->userId);
    }

    /** @return array<string, mixed> */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Épreuve introuvable.');
        $this->access->assertReadable($this->access->require($principal, $establishmentId, TeachingPermissions::EXAM_READ), $row);

        return $row;
    }

    /** @param array{exam_date: string, start_time: string, end_time: string, room_id: ?string, supervisors: list<string>} $data */
    private function assertNoConflict(string $tenantId, string $establishmentId, array $data, string $groupId): void
    {
        $errors = [];
        $candidate = ['weekday' => 1, 'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'valid_from' => $data['exam_date'], 'valid_to' => $data['exam_date']];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['exam_date' => $data['exam_date']], 'start_time', 1000) as $other) {
            if ($other['status'] === 'cancelled') {
                continue;
            }
            $existing = ['weekday' => 1, 'start_time' => (string) $other['start_time'], 'end_time' => (string) $other['end_time'], 'valid_from' => (string) $other['exam_date'], 'valid_to' => (string) $other['exam_date']];
            if (!TimetableOverlaps::overlap($candidate, $existing)) {
                continue;
            }
            if ($data['room_id'] !== null && $data['room_id'] === $other['room_id']) {
                $errors['room'][] = 'Salle déjà occupée par l\'épreuve ' . $other['element_title'] . '.';
            }
            if ($other['group_id'] === $groupId) {
                $errors['group'][] = 'Le groupe compose déjà ' . $other['element_title'] . ' sur cette plage.';
            }
            $shared = array_intersect($data['supervisors'], (array) json_decode((string) $other['supervisors'], true));
            if ($shared !== []) {
                $errors['supervisor'][] = 'Surveillant déjà affecté à ' . $other['element_title'] . '.';
            }
        }
        if ($errors !== []) {
            throw new HttpException(409, 'Conflit de planning d\'examens', (string) array_values($errors)[0][0], 'https://api.scholaris.cm/errors/exam-conflict', $errors);
        }
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Épreuve introuvable.');
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::EXAM_MANAGE);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_exam_schedule', $id);

        return $row;
    }
}
