<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\NationalExams\Domain\CandidateLifecycle;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamsStore;

/**
 * Liste des candidats (EX-EXN-02/03) : ajout manuel ou constitution depuis les inscriptions validées du niveau de sortie
 * (RG-EXN-1, aucun externe), pièces, reçu des frais, centre et numéro, retrait motivé. Toute modification après export est
 * tracée et signalée au directeur général (RG-EXN-2, ListJournal).
 */
final class CandidateService
{
    public const TABLE = 'hi_national_candidates';

    public function __construct(
        private readonly NationalExamsStore $store,
        private readonly SessionService $sessions,
        private readonly EligibilityChecker $eligibility,
        private readonly StudentDirectory $students,
        private readonly ListJournal $journal,
        private readonly NationalExamsClock $clock,
    ) {
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} candidat et session */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $candidate = $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Candidat introuvable.');
        [$session] = $this->sessions->readable($principal, $establishmentId, (string) $candidate['session_id']);

        return [$candidate, $session];
    }

    /** @return array<string, mixed> */
    public function add(Principal $principal, string $establishmentId, string $sessionId, string $personId): array
    {
        $session = $this->openSession($principal, $establishmentId, $sessionId);
        $enrolment = $this->eligibility->requireQualifying($principal->tenantId, $establishmentId, $session, $personId);
        if ($this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['session_id' => $sessionId, 'person_id' => $personId], 'id', 1) !== []) {
            throw HttpException::conflict('Cet étudiant figure déjà sur la liste.');
        }
        $row = $this->insert($principal, $establishmentId, $session, $enrolment);
        $this->journal->record($principal, $session, (string) $row['id'], 'added', ['person_id' => $personId, 'matricule' => $enrolment->matricule]);

        return $row;
    }

    /** @return array{added: int, already_listed: int} */
    public function generate(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $session = $this->openSession($principal, $establishmentId, $sessionId);
        $listed = [];
        foreach ($this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['session_id' => $sessionId], 'id', 10000) as $row) {
            $listed[(string) $row['person_id']] = true;
        }
        $added = $already = 0;
        foreach ($this->students->enrolledIn($principal->tenantId, $establishmentId, (string) $session['level_id'], (string) $session['academic_year_id']) as $enrolment) {
            if (isset($listed[$enrolment->personId])) {
                ++$already;
                continue;
            }
            $qualifying = $this->eligibility->qualifyingEnrolment($principal->tenantId, $establishmentId, $session, $enrolment->personId);
            if ($qualifying === null) {
                continue;
            }
            $row = $this->insert($principal, $establishmentId, $session, $qualifying);
            $listed[$enrolment->personId] = true;
            ++$added;
            $this->journal->record($principal, $session, (string) $row['id'], 'added', ['person_id' => $enrolment->personId, 'source' => 'generate']);
        }

        return ['added' => $added, 'already_listed' => $already];
    }

    /**
     * @param array{pieces_received?: list<string>, fee_receipt?: ?string, exam_centre?: ?string, candidate_number?: ?string} $changes
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        [$candidate, $session] = $this->writable($principal, $establishmentId, $id);
        $values = [];
        if (array_key_exists('pieces_received', $changes)) {
            $values['pieces_received'] = json_encode($changes['pieces_received'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        foreach (['fee_receipt', 'exam_centre', 'candidate_number'] as $field) {
            if (array_key_exists($field, $changes)) {
                $values[$field] = $changes[$field];
            }
        }
        $merged = $values + $candidate;
        $attendance = $this->eligibility->attendance($principal->tenantId, $establishmentId, $session, (string) $candidate['person_id']);
        $values['presence_percent'] = $attendance['presence_percent'];
        $values['attendance_ok'] = $attendance['attendance_ok'] ? 1 : 0;
        $values['status'] = CandidateLifecycle::preExportStatus((string) $candidate['status'], $attendance['attendance_ok'], self::feeSettled($session, $merged), SessionService::requiredPieces($session), self::pieces($merged));
        $values['updated_at'] = $this->clock->now();
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        $this->journal->record($principal, $session, $id, 'updated', array_intersect_key($values, array_flip(['pieces_received', 'fee_receipt', 'exam_centre', 'candidate_number', 'status'])));

        return $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id) ?? $candidate;
    }

    /** @return array<string, mixed> */
    public function withdraw(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        [$candidate, $session] = $this->writable($principal, $establishmentId, $id);
        if (in_array($candidate['status'], [CandidateLifecycle::WITHDRAWN, CandidateLifecycle::RESULTED], true)) {
            throw HttpException::conflict('Candidat déjà retiré ou résultat importé.');
        }
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, [
            'status' => CandidateLifecycle::WITHDRAWN, 'withdrawn_reason' => $reason, 'updated_at' => $this->clock->now(),
        ], ['id' => $id]);
        $this->journal->record($principal, $session, $id, 'withdrawn', ['person_id' => $candidate['person_id'], 'reason' => $reason, 'previous_status' => $candidate['status']]);

        return $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id) ?? $candidate;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $candidate
     */
    public static function feeSettled(array $session, array $candidate): bool
    {
        return (int) $session['fee_xaf'] === 0 || (is_string($candidate['fee_receipt'] ?? null) && $candidate['fee_receipt'] !== '');
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<string>
     */
    public static function pieces(array $candidate): array
    {
        $decoded = json_decode((string) ($candidate['pieces_received'] ?? '[]'), true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function writable(Principal $principal, string $establishmentId, string $id): array
    {
        [$candidate, $session] = $this->readable($principal, $establishmentId, $id);
        $this->sessions->writable($principal, $establishmentId, (string) $session['id']);
        if ($session['status'] === CandidateLifecycle::SESSION_CLOSED) {
            throw HttpException::conflict('Session close : la liste ne se modifie plus.');
        }

        return [$candidate, $session];
    }

    /** @return array<string, mixed> */
    private function openSession(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $session = $this->sessions->writable($principal, $establishmentId, $sessionId);
        if ($session['status'] === CandidateLifecycle::SESSION_CLOSED) {
            throw HttpException::conflict('Session close : la liste ne se modifie plus.');
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function insert(Principal $principal, string $establishmentId, array $session, EnrolmentInfo $enrolment): array
    {
        $attendance = $this->eligibility->attendance($principal->tenantId, $establishmentId, $session, $enrolment->personId);
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(), 'session_id' => (string) $session['id'], 'enrolment_id' => $enrolment->id, 'person_id' => $enrolment->personId,
            'matricule' => $enrolment->matricule, 'school_id' => (string) $session['school_id'], 'department_id' => (string) $session['department_id'],
            'program_id' => $enrolment->programId, 'level_id' => $enrolment->levelId, 'group_id' => $enrolment->groupId,
            'presence_percent' => $attendance['presence_percent'], 'attendance_ok' => $attendance['attendance_ok'] ? 1 : 0, 'pieces_received' => '[]',
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $row['status'] = CandidateLifecycle::preExportStatus(CandidateLifecycle::ELIGIBLE, $attendance['attendance_ok'], self::feeSettled($session, $row), SessionService::requiredPieces($session), []);
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, $row);

        return $row;
    }
}
