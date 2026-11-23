<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\AttendanceRegisterService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\DailyAttendanceService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceCalls;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;

/**
 * API-05 : appel d'une classe par demi-journée (PUT, enseignant ou directeur), lecture de l'état du jour et données du cahier
 * d'appel mensuel (DOC-06). Le nominatif (liste des absents, cahier) n'est rendu qu'à l'école elle-même : la supervision
 * territoriale ne reçoit que des effectifs (RG-TER-2).
 */
final class AttendanceController
{
    private const DAY_PARTS = ['morning', 'afternoon'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly DailyAttendanceService $attendance,
        private readonly PdoAttendanceCalls $calls,
        private readonly DailyPresence $presence,
        private readonly ClassRoster $classes,
        private readonly AttendanceRegisterService $registers,
    ) {
    }

    public function record(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $structureId = RequestInput::attribute($request, 'id');
        $date = RequestInput::attribute($request, 'date');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.attendance.write', $establishmentId);
        $this->scope->assertWritable($principal, $perimeter, $structureId, 'attendance_session', $structureId . ':' . $date);

        $body = RequestInput::body($request);
        $dayPart = is_string($body['day_part'] ?? null) ? $body['day_part'] : '';
        $clientUuid = is_string($body['client_uuid'] ?? null) ? trim($body['client_uuid']) : '';
        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : null;
        $exceptions = self::records($body['records'] ?? null);
        $session = $this->attendance->submit(
            $principal->tenantId,
            $principal,
            $establishmentId,
            $structureId,
            $date,
            $dayPart,
            $clientUuid,
            $exceptions,
            $reason,
        );

        return $this->responder->json(['data' => [
            'id' => $session->id,
            'establishment_id' => $session->establishmentId,
            'structure_id' => $session->structureId,
            'date' => $session->date,
            'day_part' => $session->dayPart,
            'status' => $session->status,
            'records' => array_map(static fn ($mark): array => [
                'person_id' => $mark->personId,
                'status' => $mark->status,
                'arrived_at' => $mark->arrivedAt,
                'note' => $mark->note,
            ], $session->marks),
        ]]);
    }

    /** État de l'appel d'une classe un jour donné : demi-journées faites ou manquantes, effectifs, et absents pour l'école. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $structureId = RequestInput::attribute($request, 'id');
        $date = self::date(RequestInput::attribute($request, 'date'));
        $perimeter = $this->scope->perimeter($principal, 'public_schools.attendance.read', $establishmentId);
        $this->scope->assertReadable($perimeter, $structureId);
        $class = $this->classes->find($principal->tenantId, $establishmentId, $structureId);
        if ($class === null) {
            throw HttpException::notFound('Classe introuvable dans cet établissement.');
        }

        $byPart = [];
        foreach ($this->calls->forDay($principal->tenantId, $establishmentId, $structureId, $date) as $call) {
            $byPart[(string) $call['day_part']] = $call;
        }
        $parts = [];
        foreach (self::DAY_PARTS as $part) {
            $call = $byPart[$part] ?? null;
            $entry = ['day_part' => $part, 'taken' => $call !== null];
            if ($call !== null) {
                $entry += [
                    'headcount' => (int) $call['headcount'],
                    'absent_count' => (int) $call['absent_count'],
                    'late_count' => (int) $call['late_count'],
                    'recorded_at' => (string) $call['recorded_at'],
                    'corrected_at' => $call['corrected_at'],
                ];
                if ($perimeter->isInSchool()) {
                    $entry['absent_person_ids'] = $this->presence->absentOn($principal->tenantId, $establishmentId, $date, $structureId, $part);
                    $entry['correction_reason'] = $call['correction_reason'];
                }
            }
            $parts[] = $entry;
        }

        return $this->responder->json(['data' => [
            'structure_id' => $structureId,
            'date' => $date,
            'headcount' => $this->classes->headcount($principal->tenantId, $establishmentId, $structureId, $date),
            'parts' => $parts,
        ]]);
    }

    /** DOC-06 : données du cahier d'appel mensuel (`?month=AAAA-MM`), nominatives. */
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $structureId = RequestInput::attribute($request, 'id');
        $month = RequestInput::query($request, 'month');
        if ($month === null) {
            throw HttpException::validation(['month' => ['Mois au format AAAA-MM requis.']]);
        }
        $perimeter = $this->scope->perimeter($principal, 'public_schools.attendance.read', $establishmentId);
        $this->scope->assertReadable($perimeter, $structureId);
        if (!$perimeter->isInSchool()) {
            throw HttpException::forbidden('Le cahier d’appel est nominatif : il n’est lisible que dans l’école.');
        }

        return $this->responder->json(['data' => $this->registers->month($principal->tenantId, $establishmentId, $structureId, $month)]);
    }

    /** @return list<array{person_id: string, status: string, arrived_at?: ?string, note?: ?string}> */
    private static function records(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw HttpException::validation(['records' => ['Liste d’exceptions attendue.']]);
        }
        $records = [];
        $errors = [];
        foreach ($value as $index => $record) {
            if (!is_array($record)) {
                $errors['records.' . $index][] = 'Objet attendu.';
                continue;
            }
            $personId = $record['person_id'] ?? null;
            $status = $record['status'] ?? null;
            if (!is_string($personId) || trim($personId) === '') {
                $errors['records.' . $index . '.person_id'][] = 'Identifiant requis.';
                continue;
            }
            if (!is_string($status) || !in_array($status, ['P', 'A', 'R'], true)) {
                $errors['records.' . $index . '.status'][] = 'Valeur attendue : P, A ou R.';
                continue;
            }
            $line = ['person_id' => trim($personId), 'status' => $status];
            foreach (['arrived_at' => 5, 'note' => 255] as $field => $max) {
                if (!array_key_exists($field, $record)) {
                    continue;
                }
                if ($record[$field] !== null && (!is_string($record[$field]) || mb_strlen($record[$field]) > $max)) {
                    $errors['records.' . $index . '.' . $field][] = 'Texte invalide.';
                    continue;
                }
                $line[$field] = $record[$field];
            }
            $records[] = $line;
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $records;
    }

    private static function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $date;
    }
}
