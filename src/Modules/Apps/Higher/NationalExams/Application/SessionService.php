<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\NationalExams\Domain\CandidateLifecycle;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamsStore;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;

/**
 * Sessions d'examen national (EX-EXN-01) : type BTS ou HND, filière (spécialité) de même régime, niveau de sortie, calendrier
 * MINESUP, période d'assiduité, frais et pièces exigées. Une session par filière, année et type (409).
 */
final class SessionService
{
    public const TABLE = 'hi_national_sessions';

    public function __construct(
        private readonly NationalExamsStore $store,
        private readonly NationalExamsAccess $access,
        private readonly AcademicDirectory $academic,
        private readonly NationalExamsClock $clock,
    ) {
    }

    /** @return array{0: array<string, mixed>, 1: Perimeter} */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, NationalExamsPermissions::READ);
        $session = $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Session introuvable.');
        $this->access->assertReadable($perimeter, $session);

        return [$session, $perimeter];
    }

    /** @return array<string, mixed> */
    public function writable(Principal $principal, string $establishmentId, string $id, string $permission = NationalExamsPermissions::MANAGE): array
    {
        [$session] = $this->readable($principal, $establishmentId, $id);
        $perimeter = $this->access->require($principal, $establishmentId, [$permission]);
        $this->access->assertWritable($principal, $perimeter, $session, 'national_session', $id);

        return $session;
    }

    /**
     * @param array<string, mixed> $data validées par le contrôleur
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, [NationalExamsPermissions::MANAGE]);
        $tenantId = $principal->tenantId;
        $program = $this->academic->program($tenantId, $establishmentId, (string) $data['program_id']);
        if ($program === null) {
            throw HttpException::validation(['program_id' => ['Filière introuvable.']]);
        }
        if ($program->regime !== $data['exam_type']) {
            throw HttpException::validation(['exam_type' => [sprintf('La filière relève du régime %s : session %s impossible.', $program->regime, $data['exam_type'])]]);
        }
        $final = null;
        foreach ($this->academic->levelsOf($tenantId, $establishmentId, $program->id) as $level) {
            if ($level->isFinal) {
                $final = $level;
            }
        }
        if ($final === null) {
            throw HttpException::validation(['program_id' => ['Aucun niveau de sortie (dernier niveau) n\'est défini pour cette filière.']]);
        }
        if ((string) $data['attendance_from'] > (string) $data['attendance_to']) {
            throw HttpException::validation(['attendance_to' => ['La fin de la période d\'assiduité précède son début.']]);
        }
        $row = [
            'id' => (string) new Ulid(), 'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id,
            'level_id' => $final->id, 'academic_year_id' => (string) $data['academic_year_id'], 'exam_type' => (string) $data['exam_type'],
            'specialty_label' => (string) ($data['specialty_label'] ?? $program->name), 'calendar_ref' => $data['calendar_ref'] ?? null,
            'registration_deadline' => $data['registration_deadline'] ?? null, 'exam_starts_on' => $data['exam_starts_on'] ?? null,
            'attendance_from' => (string) $data['attendance_from'], 'attendance_to' => (string) $data['attendance_to'], 'fee_xaf' => (int) ($data['fee_xaf'] ?? 0),
            'required_pieces' => json_encode(array_values($data['required_pieces'] ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => CandidateLifecycle::SESSION_OPEN, 'export_count' => 0, 'created_by' => $principal->userId,
            'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
        ];
        $this->access->assertWritable($principal, $perimeter, $row, 'national_session', $row['id']);
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);
        $this->access->audit($principal, 'higher.national_session.created', 'national_session', $row['id'], null, $row);

        return $this->store->find(self::TABLE, $tenantId, $establishmentId, $row['id']) ?? $row;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $session = $this->writable($principal, $establishmentId, $id);
        if ($session['status'] === CandidateLifecycle::SESSION_CLOSED) {
            throw HttpException::conflict('Session close : aucune modification.');
        }
        if (isset($changes['required_pieces'])) {
            $changes['required_pieces'] = json_encode(array_values((array) $changes['required_pieces']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if (($changes['status'] ?? null) === CandidateLifecycle::SESSION_CLOSED && (int) $session['export_count'] === 0) {
            throw HttpException::conflict('Une session ne se clôt qu\'après l\'export de sa liste.');
        }
        $changes['updated_at'] = $this->clock->now();
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'higher.national_session.updated', 'national_session', $id, $session, $changes);

        return $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id) ?? $session;
    }

    /**
     * @param array<string, mixed> $session
     * @return list<string>
     */
    public static function requiredPieces(array $session): array
    {
        $decoded = json_decode((string) ($session['required_pieces'] ?? '[]'), true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
