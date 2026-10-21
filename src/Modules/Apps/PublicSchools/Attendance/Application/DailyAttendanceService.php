<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceRecorder;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSession;

/** Orchestration de l'appel d'une classe sur le contrat du socle Attendance. */
final class DailyAttendanceService
{
    public function __construct(
        private readonly AttendanceRecorder $recorder,
        private readonly ClassRoster $classes,
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
            $clientUuid,
            $line['note'] ?? null,
        ), $exceptions);

        if ($session->status === 'validated') {
            $fullRoster = $this->fullRoster($tenantId, $establishmentId, $structureId, $date, $marks);
            if (self::sameMarks($session->marks, $fullRoster)) {
                return $session;
            }
            if (!$allowCorrection) {
                throw HttpException::conflict('Une autre version de cet appel est déjà validée. Aucune donnée n’a été écrasée.');
            }

            return $this->recorder->correct($tenantId, $principal, $establishmentId, $session->id, $fullRoster, $reason);
        }

        $recorded = $this->recorder->record($tenantId, $principal, $establishmentId, $session->id, $structureId, $marks);

        return $this->recorder->validate($tenantId, $principal, $establishmentId, $recorded->id);
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
