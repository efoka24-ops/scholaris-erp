<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceCalls;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceRecorder;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSession;

/**
 * Orchestration de l'appel d'une classe sur le contrat du socle Attendance (T634).
 *
 * Tous présents par défaut : seules les exceptions (absent, en retard) sont transmises, le contrat complète la liste de la classe.
 * RG-PRE-1 : l'enseignant auteur de l'appel le corrige le jour même ; le lendemain et après, le socle exige le droit
 * `attendance.correct_late` (directeur) et un motif. Les absences ne déclenchent aucune sanction (RG-PRE-3).
 */
final class DailyAttendanceService
{
    public const RECORDED = 'recorded';
    public const REPLAYED = 'replayed';
    public const CORRECTED = 'corrected';

    public function __construct(
        private readonly AttendanceRecorder $recorder,
        private readonly ClassRoster $classes,
        private readonly PdoAttendanceCalls $calls,
        private readonly CallClock $clock,
    ) {
    }

    /** @param list<array{person_id: string, status: string, arrived_at?: ?string, note?: ?string}> $exceptions */
    public function submit(
        string $tenantId,
        Principal $principal,
        string $establishmentId,
        string $structureId,
        string $date,
        string $dayPart,
        string $clientUuid,
        array $exceptions,
        ?string $reason = null,
        bool $allowCorrection = true,
    ): AttendanceSession {
        return $this->process($tenantId, $principal, $establishmentId, $structureId, $date, $dayPart, $clientUuid, $exceptions, $reason, $allowCorrection)['session'];
    }

    /**
     * Même chose que submit(), avec l'issue : première saisie, rejeu identique (aucune écriture) ou correction.
     *
     * @param list<array{person_id: string, status: string, arrived_at?: ?string, note?: ?string}> $exceptions
     * @return array{session: AttendanceSession, outcome: string}
     */
    public function process(
        string $tenantId,
        Principal $principal,
        string $establishmentId,
        string $structureId,
        string $date,
        string $dayPart,
        string $clientUuid,
        array $exceptions,
        ?string $reason = null,
        bool $allowCorrection = true,
    ): array {
        if (!in_array($dayPart, ['morning', 'afternoon'], true)) {
            throw HttpException::validation(['day_part' => ['Valeur attendue : morning ou afternoon.']]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !self::isDate($date)) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }
        if ($clientUuid === '' || strlen($clientUuid) > 40) {
            throw HttpException::validation(['client_uuid' => ['Identifiant client requis (40 caractères au plus).']]);
        }
        if ($this->classes->find($tenantId, $establishmentId, $structureId) === null) {
            throw HttpException::notFound('Classe introuvable dans cet établissement.');
        }

        $session = $this->recorder->openDayPart($tenantId, $principal, $establishmentId, $structureId, $dayPart, $date, $clientUuid);
        $marks = array_map(static fn (array $line): AttendanceMark => new AttendanceMark(
            $line['person_id'],
            $line['status'],
            $line['arrived_at'] ?? null,
            null,
            $line['note'] ?? null,
        ), $exceptions);

        if ($session->status === 'validated') {
            $fullRoster = $this->fullRoster($tenantId, $establishmentId, $structureId, $date, $marks);
            if (self::sameMarks($session->marks, $fullRoster)) {
                return ['session' => $session, 'outcome' => self::REPLAYED];
            }
            if (!$allowCorrection) {
                throw HttpException::conflict('Une autre version de cet appel est déjà validée. Aucune donnée n’a été écrasée.');
            }
            $corrected = $this->recorder->correct($tenantId, $principal, $establishmentId, $session->id, $fullRoster, $reason);
            $this->track($tenantId, $establishmentId, $principal, $corrected, trim((string) $reason));

            return ['session' => $corrected, 'outcome' => self::CORRECTED];
        }

        $recorded = $this->recorder->record($tenantId, $principal, $establishmentId, $session->id, $structureId, $marks);
        $validated = $this->recorder->validate($tenantId, $principal, $establishmentId, $recorded->id);
        $this->track($tenantId, $establishmentId, $principal, $validated, null);

        return ['session' => $validated, 'outcome' => self::RECORDED];
    }

    private function track(string $tenantId, string $establishmentId, Principal $principal, AttendanceSession $session, ?string $correctionReason): void
    {
        $absent = 0;
        $late = 0;
        foreach ($session->marks as $mark) {
            if ($mark->status === AttendanceMark::ABSENT) {
                ++$absent;
            } elseif ($mark->status === AttendanceMark::LATE) {
                ++$late;
            }
        }
        $this->calls->record(
            $tenantId,
            $establishmentId,
            $session->structureId,
            $session->date,
            $session->dayPart,
            $session->id,
            count($session->marks),
            $absent,
            $late,
            $principal->userId,
            $this->clock->now(),
            $correctionReason === '' ? null : $correctionReason,
        );
    }

    /** @param list<AttendanceMark> $exceptions
     * @return list<AttendanceMark>
     */
    private function fullRoster(string $tenantId, string $establishmentId, string $structureId, string $date, array $exceptions): array
    {
        $byPerson = [];
        foreach ($exceptions as $mark) {
            if (isset($byPerson[$mark->personId])) {
                throw HttpException::validation(['records' => ['Un élève ne peut apparaître qu’une fois.']]);
            }
            $byPerson[$mark->personId] = $mark;
        }
        $roster = [];
        foreach ($this->classes->pupilIdsIn($tenantId, $establishmentId, $structureId, $date) as $personId) {
            $roster[] = $byPerson[$personId] ?? new AttendanceMark($personId);
            unset($byPerson[$personId]);
        }
        if ($byPerson !== []) {
            throw HttpException::validation(['records' => ['Une exception concerne un élève hors de cette classe.']]);
        }

        return $roster;
    }

    private static function isDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** @param list<AttendanceMark> $current
     * @param list<AttendanceMark> $incoming
     */
    private static function sameMarks(array $current, array $incoming): bool
    {
        $signature = static function (array $marks): array {
            $values = [];
            foreach ($marks as $mark) {
                $values[$mark->personId] = [$mark->status, $mark->arrivedAt, $mark->note];
            }
            ksort($values);

            return $values;
        };

        return $signature($current) === $signature($incoming);
    }
}
