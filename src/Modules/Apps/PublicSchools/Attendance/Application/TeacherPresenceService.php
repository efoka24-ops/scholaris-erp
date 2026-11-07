<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceAlerts;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoTeacherPresence;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/**
 * Présence des enseignants (T636, EX-PRE-03 et EX-PRE-04) : arrivée et départ déclarés par le directeur, absences et permissions avec
 * pièce, contrôle aléatoire de l'inspection, et RG-PRE-2 : un enseignant absent sans remplaçant laisse sa classe « sans enseignant »
 * pour la journée (NOT-13, au directeur puis à l'inspection). Un contrôle de l'inspection verrouille la déclaration du jour.
 */
final class TeacherPresenceService
{
    public const PRESENCES = ['present', 'absent', 'permission', 'mission'];
    public const CHECK_RESULTS = ['confirmed', 'discrepancy'];
    private const DEFAULT_SAMPLE_SIZE = 3;

    /** Absences qui laissent la classe sans enseignant en l'absence de remplaçant. */
    private const AWAY = ['absent', 'permission', 'mission'];

    public function __construct(
        private readonly PdoTeacherPresence $presence,
        private readonly PdoAttendanceAlerts $alerts,
        private readonly StaffDirectory $staff,
        private readonly ClassRoster $classes,
        private readonly CurrentPeriod $periods,
        private readonly PublicMessages $messages,
        private readonly SettingsReader $settings,
        private readonly AuditLog $audit,
        private readonly CallClock $clock,
    ) {
    }

    /**
     * Déclaration du directeur pour un enseignant et un jour ; une nouvelle déclaration corrige la précédente tant que l'inspection ne l'a pas contrôlée.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function declare(Principal $principal, string $establishmentId, string $personId, string $date, array $input): array
    {
        $tenantId = $principal->tenantId;
        $teacher = $this->teacher($tenantId, $establishmentId, $personId);
        $staffId = (string) $teacher->staffId;
        self::assertDate($date, 'date');
        if ($date > $this->clock->today()) {
            throw HttpException::validation(['date' => ['La présence ne peut pas être déclarée pour un jour à venir.']]);
        }
        $values = $this->validated($tenantId, $establishmentId, $input);

        $existing = $this->presence->find($tenantId, $establishmentId, $staffId, $date);
        if ($values['client_uuid'] !== null) {
            $sameClient = $this->presence->findByClientUuid($tenantId, $establishmentId, $values['client_uuid']);
            if ($sameClient !== null && ($sameClient['staff_id'] !== $staffId || $sameClient['day_on'] !== $date)) {
                throw HttpException::conflict('Ce client_uuid identifie déjà une autre déclaration.');
            }
        }
        if ($existing !== null && $existing['check_result'] !== null) {
            throw HttpException::conflict('Cette présence a été contrôlée par l’inspection : elle ne peut plus être modifiée.');
        }

        $row = [
            'presence' => $values['presence'],
            'arrived_at' => $values['arrived_at'],
            'left_at' => $values['left_at'],
            'reason' => $values['reason'],
            'proof_path' => $values['proof_path'],
            'replacement_staff_id' => $values['replacement_staff_id'],
        ];
        if ($existing === null) {
            $id = $this->presence->insert($tenantId, $establishmentId, $row + [
                'staff_id' => $staffId, 'day_on' => $date, 'declared_by' => $principal->userId, 'client_uuid' => $values['client_uuid'],
            ]);
        } else {
            $id = (string) $existing['id'];
            $this->presence->update($tenantId, $id, $row + ['declared_by' => $principal->userId]);
        }
        $this->audit->append($tenantId, $principal->userId, 'public_schools.teacher_presence.declared', 'teacher_presence', $id, null, ['staff_id' => $staffId, 'day' => $date, 'presence' => $values['presence']]);
        $uncovered = $this->refreshUncovered($tenantId, $establishmentId, $staffId, $date, $values['presence'], $values['replacement_staff_id']);

        return $this->resource($this->mustFind($tenantId, $establishmentId, $staffId, $date)) + ['uncovered_class_ids' => $uncovered];
    }

    /**
     * Contrôle de l'inspection : confirme ou conteste la déclaration du jour.
     *
     * @return array<string, mixed>
     */
    public function check(Principal $principal, string $establishmentId, string $personId, string $date, string $result): array
    {
        $tenantId = $principal->tenantId;
        if (!in_array($result, self::CHECK_RESULTS, true)) {
            throw HttpException::validation(['result' => ['Valeur attendue : ' . implode(' ou ', self::CHECK_RESULTS) . '.']]);
        }
        $teacher = $this->teacher($tenantId, $establishmentId, $personId);
        $row = $this->presence->find($tenantId, $establishmentId, (string) $teacher->staffId, $date);
        if ($row === null) {
            throw HttpException::notFound('Aucune présence déclarée pour cet enseignant ce jour-là.');
        }
        if ($row['check_result'] !== null) {
            throw HttpException::conflict('Cette présence a déjà été contrôlée.');
        }
        $this->presence->update($tenantId, (string) $row['id'], ['checked_by' => $principal->userId, 'checked_at' => $this->clock->now(), 'check_result' => $result]);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.teacher_presence.checked', 'teacher_presence', (string) $row['id'], null, ['result' => $result, 'day' => $date]);

        return $this->resource($this->mustFind($tenantId, $establishmentId, (string) $teacher->staffId, $date));
    }

    /**
     * Échantillon aléatoire de déclarations « présent » non encore contrôlées (EX-PRE-03). Taille : `public_schools.inspection.sample_size`.
     *
     * @return list<array<string, mixed>>
     */
    public function sample(string $tenantId, string $establishmentId, string $date): array
    {
        self::assertDate($date, 'date');
        $size = $this->settings->value($tenantId, $establishmentId, 'public_schools.inspection.sample_size', self::DEFAULT_SAMPLE_SIZE);
        $size = is_numeric($size) && (int) $size > 0 ? (int) $size : self::DEFAULT_SAMPLE_SIZE;
        $candidates = array_values(array_filter(
            $this->presence->ofDay($tenantId, $establishmentId, $date, null, 1000),
            static fn (array $row): bool => $row['presence'] === 'present' && $row['check_result'] === null,
        ));
        shuffle($candidates);

        return array_map(fn (array $row): array => $this->resource($row), array_slice($candidates, 0, $size));
    }

    /**
     * Synthèse mensuelle par enseignant (DOC-22) : jours déclarés par type de présence, jours contrôlés et contestés.
     *
     * @return array{month: string, teachers: list<array<string, mixed>>, uncovered_days: int}
     */
    public function monthlySummary(string $tenantId, string $establishmentId, string $month): array
    {
        [$from, $to] = self::monthBounds($month);
        /** @var array<string, array<string, int>> $counts */
        $counts = [];
        foreach ($this->presence->between($tenantId, $establishmentId, $from, $to) as $row) {
            $staffId = (string) $row['staff_id'];
            $counts[$staffId] ??= ['present' => 0, 'absent' => 0, 'permission' => 0, 'mission' => 0, 'checked' => 0, 'discrepancies' => 0];
            ++$counts[$staffId][(string) $row['presence']];
            if ($row['check_result'] !== null) {
                ++$counts[$staffId]['checked'];
            }
            if ($row['check_result'] === 'discrepancy') {
                ++$counts[$staffId]['discrepancies'];
            }
        }
        $teachers = [];
        foreach ($counts as $staffId => $line) {
            $teachers[] = ['staff_id' => (string) $staffId] + $line;
        }
        $uncoveredDays = $this->presence->countUncoveredBetween($tenantId, $establishmentId, $from, $to);

        return ['month' => $month, 'teachers' => $teachers, 'uncovered_days' => $uncoveredDays];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'staff_id' => (string) $row['staff_id'],
            'date' => (string) $row['day_on'],
            'presence' => (string) $row['presence'],
            'arrived_at' => $row['arrived_at'],
            'left_at' => $row['left_at'],
            'reason' => $row['reason'],
            'proof_path' => $row['proof_path'],
            'replacement_staff_id' => $row['replacement_staff_id'] ?? null,
            'declared_by' => (string) $row['declared_by'],
            'checked_by' => $row['checked_by'],
            'checked_at' => $row['checked_at'],
            'check_result' => $row['check_result'],
        ];
    }

    /** @return array<string, mixed> */
    private function mustFind(string $tenantId, string $establishmentId, string $staffId, string $date): array
    {
        $row = $this->presence->find($tenantId, $establishmentId, $staffId, $date);
        if ($row === null) {
            throw HttpException::notFound('Présence introuvable.');
        }

        return $row;
    }

    private function teacher(string $tenantId, string $establishmentId, string $personId): StaffInfo
    {
        $info = in_array($personId, $this->staff->teacherIdsIn($tenantId, $establishmentId), true) ? $this->staff->find($tenantId, $personId) : null;
        if ($info === null || $info->staffId === null) {
            throw HttpException::notFound('Enseignant introuvable dans cet établissement.');
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{presence: string, arrived_at: ?string, left_at: ?string, reason: ?string, proof_path: ?string, replacement_staff_id: ?string, client_uuid: ?string}
     */
    private function validated(string $tenantId, string $establishmentId, array $input): array
    {
        $errors = [];
        $presence = $input['presence'] ?? null;
        if (!is_string($presence) || !in_array($presence, self::PRESENCES, true)) {
            $errors['presence'][] = 'Valeur attendue : ' . implode(', ', self::PRESENCES) . '.';
            $presence = '';
        }
        $times = [];
        foreach (['arrived_at', 'left_at'] as $field) {
            $value = $input[$field] ?? null;
            if ($value !== null && (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1)) {
                $errors[$field][] = 'Heure au format HH:MM attendue.';
                $value = null;
            }
            $times[$field] = $value;
        }
        $reason = self::optionalText($input['reason'] ?? null, 255, 'reason', $errors);
        $proof = self::optionalText($input['proof_path'] ?? null, 255, 'proof_path', $errors);
        $clientUuid = self::optionalText($input['client_uuid'] ?? null, 36, 'client_uuid', $errors);
        if (in_array($presence, self::AWAY, true) && $reason === null) {
            $errors['reason'][] = 'Un motif est requis pour une absence, une permission ou une mission.';
        }
        if ($presence === 'permission' && $proof === null && $this->proofRequired($tenantId, $establishmentId)) {
            $errors['proof_path'][] = 'Une permission s’accompagne d’une pièce.';
        }
        $replacement = null;
        $replacementPerson = self::optionalText($input['replacement_person_id'] ?? null, 40, 'replacement_person_id', $errors);
        if ($errors === [] && $replacementPerson !== null) {
            if ($presence === 'present') {
                $errors['replacement_person_id'][] = 'Pas de remplaçant pour un enseignant présent.';
            } else {
                $replacement = $this->teacher($tenantId, $establishmentId, $replacementPerson)->staffId;
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'presence' => $presence,
            'arrived_at' => $times['arrived_at'],
            'left_at' => $times['left_at'],
            'reason' => $reason,
            'proof_path' => $proof,
            'replacement_staff_id' => $replacement,
            'client_uuid' => $clientUuid,
        ];
    }

    private function proofRequired(string $tenantId, string $establishmentId): bool
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'public_schools.teacher_presence.proof_required', true);

        return !in_array($value, [false, 0, '0', 'false', 'off'], true);
    }

    /**
     * RG-PRE-2 : met à jour les classes sans enseignant du jour pour le titulaire concerné.
     *
     * @return list<string> classes sans enseignant pour ce jour après la mise à jour
     */
    private function refreshUncovered(string $tenantId, string $establishmentId, string $staffId, string $date, string $presence, ?string $replacementStaffId): array
    {
        $period = $this->periods->at($tenantId, $establishmentId, $date);
        if ($period === null) {
            return [];
        }
        $uncovered = [];
        foreach ($this->classes->classesOf($tenantId, $establishmentId, $period->academicYearId) as $class) {
            if ($class->homeroomStaffId !== $staffId) {
                continue;
            }
            $without = in_array($presence, self::AWAY, true) && $replacementStaffId === null;
            $isNew = $this->presence->setUncovered($tenantId, $establishmentId, $class->structureId, $date, $without);
            if (!$without) {
                continue;
            }
            $uncovered[] = $class->structureId;
            if ($isNew) {
                $this->raiseUncovered($tenantId, $establishmentId, $class->structureId, $class->label, $date);
            }
        }

        return $uncovered;
    }

    /** NOT-13 : le directeur est prévenu par message ; l'inspection voit l'alerte dans son fil (GET /public/attendance-alerts). */
    private function raiseUncovered(string $tenantId, string $establishmentId, string $structureId, string $label, string $date): void
    {
        $now = $this->clock->now();
        if ($this->alerts->raise($tenantId, $establishmentId, PdoAttendanceAlerts::UNCOVERED_CLASS, $structureId, $date, PdoAttendanceAlerts::DIRECTOR, $date, $now)) {
            $reached = $this->messages->notifyDirector($tenantId, $establishmentId, 'pub.not13.director', ['class_name' => $label, 'date' => $date]);
            $this->alerts->setRecipients($tenantId, $establishmentId, PdoAttendanceAlerts::UNCOVERED_CLASS, $structureId, $date, PdoAttendanceAlerts::DIRECTOR, $reached ? 1 : 0);
        }
        $this->alerts->raise($tenantId, $establishmentId, PdoAttendanceAlerts::UNCOVERED_CLASS, $structureId, $date, PdoAttendanceAlerts::INSPECTION, $date, $now);
    }

    /** @return array{0: string, 1: string} premier et dernier jour du mois AAAA-MM */
    public static function monthBounds(string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw HttpException::validation(['month' => ['Mois au format AAAA-MM attendu.']]);
        }
        $first = new \DateTimeImmutable($month . '-01');

        return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }

    private static function assertDate(string $date, string $field): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw HttpException::validation([$field => ['Date au format AAAA-MM-JJ attendue.']]);
        }
    }

    /** @param array<string, list<string>> $errors */
    private static function optionalText(mixed $value, int $max, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            $errors[$field][] = 'Texte de ' . $max . ' caractères au plus attendu.';

            return null;
        }

        return trim($value);
    }
}
