<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\DailyAttendanceService;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;

final class AttendanceController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly DailyAttendanceService $attendance,
    ) {
    }

    public function record(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishmentId = self::attribute($request, 'establishment_id');
        $structureId = self::attribute($request, 'id');
        $date = self::attribute($request, 'date');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.attendance.write', $establishmentId);
        $this->scope->assertWritable($principal, $perimeter, $structureId, 'attendance_session', $structureId . ':' . $date);

        $body = self::body($request);
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

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            throw HttpException::validation(['body' => ['JSON attendu.']]);
        }

        return $body;
    }

    private static function attribute(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : throw HttpException::badRequest('Paramètre de route manquant.');
    }
}
