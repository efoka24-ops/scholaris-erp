<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Teaching\Application\ConsumptionService;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingAccess;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingClock;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TimetableService;
use Scholaris\Modules\Apps\Higher\Teaching\Documents\TeachingDocumentTypes;

/**
 * Emploi du temps (EX-EDT-01 à 05) : vues par groupe, enseignant ou salle ; contrôle à blanc ; pose et retrait de créneaux ;
 * conflits ; reports et remplacements notifiés (NOT-05) ; consommation des volumes par EC ; emploi du temps imprimable (DOC-07).
 */
final class TimetableController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly TimetableService $timetable,
        private readonly ConsumptionService $consumption,
        private readonly HigherDocumentIssuer $documents,
        private readonly TeachingClock $clock,
    ) {
    }

    public function view(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filters = [];
        foreach (['group_id', 'person_id', 'room_id', 'week'] as $key) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $filters[$key] = $query[$key];
            }
        }
        if (isset($filters['week']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['week']) !== 1) {
            throw HttpException::validation(['week' => ['Date AAAA-MM-JJ attendue.']]);
        }
        $view = $this->timetable->view(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), $filters, TeachingAccess::selectedSchool($request));

        return $this->responder->json([
            'entries' => array_map(static fn (array $row): array => TeachingResources::of('entry', $row), $view['entries']),
            'changes' => array_map(TeachingResources::mapper('change'), $view['changes']),
        ]);
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->timetable->check(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), self::slot(Body::of($request))));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(TeachingResources::of('entry', $this->timetable->create(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), self::slot(Body::of($request)))), 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(TeachingResources::of('entry', $this->timetable->readableEntry(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'))));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->timetable->delete(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'));

        return $this->responder->json(['deleted' => true]);
    }

    public function conflicts(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->timetable->conflicts(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingAccess::selectedSchool($request))]);
    }

    public function change(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $data = [
            'entry_id' => (string) $body->string('entry_id', 40),
            'change_kind' => (string) $body->choice('change_kind', TimetableService::CHANGE_KINDS),
            'occurs_on' => (string) $body->date('occurs_on'),
            'new_date' => $body->date('new_date', false),
            'new_start_time' => self::time($body, 'new_start_time', false),
            'new_end_time' => self::time($body, 'new_end_time', false),
            'replacement_person_id' => $body->string('replacement_person_id', 40, false),
            'new_room_id' => $body->string('new_room_id', 40, false),
            'reason' => (string) $body->string('reason', 500),
        ];
        $body->done();

        return $this->responder->json(TeachingResources::of('change', $this->timetable->change(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), $data)), 201);
    }

    public function consumption(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filters = [];
        foreach (['curriculum_id', 'element_id', 'group_id'] as $key) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $filters[$key] = $query[$key];
            }
        }
        $from = is_string($query['from'] ?? null) ? $query['from'] : $this->clock->plusDays(-180);
        $to = is_string($query['to'] ?? null) ? $query['to'] : $this->clock->plusDays(180);
        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw HttpException::validation([$key => ['Date AAAA-MM-JJ attendue.']]);
            }
        }
        if ($to < $from || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 365) {
            throw HttpException::validation(['to' => ['Période de 366 jours au plus, fin après le début.']]);
        }
        $rows = $this->consumption->report(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), $filters, $from, $to, TeachingAccess::selectedSchool($request));

        return $this->responder->json(['data' => $rows, 'meta' => ['from' => $from, 'to' => $to, 'unit' => 'minutes']]);
    }

    /** DOC-07 : emploi du temps d'un étudiant (ou d'un enseignant) pour une semaine, à la demande. */
    public function document(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $personId = (string) $body->string('person_id', 40);
        $groupId = (string) $body->string('group_id', 40);
        $week = (string) $body->date('week');
        $body->done();
        $principal = TeachingAccess::principal($request);
        $establishmentId = TeachingAccess::establishmentId($request);
        $view = $this->timetable->view($principal, $establishmentId, ['group_id' => $groupId, 'week' => $week], null);
        if ($view['entries'] === []) {
            throw HttpException::conflict('Aucun créneau pour ce groupe cette semaine.');
        }
        $first = $view['entries'][0];
        $days = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];
        $summary = implode(' ; ', array_map(static fn (array $e): string => sprintf('%s %s-%s %s', $days[(int) $e['weekday']] ?? '', $e['start_time'], $e['end_time'], $e['element_title']), $view['entries']));
        $document = $this->documents->issueFor($principal->tenantId, $establishmentId, TeachingDocumentTypes::TIMETABLE, $personId, (string) $first['school_id'], (string) $first['program_id'], (string) $first['level_id'], [
            'group_name' => $groupId, 'week_of' => $week, 'timetable_summary' => mb_substr($summary, 0, 2000),
        ], $principal->userId);

        return $this->responder->json($document, 201);
    }

    /**
     * @return array{assignment_id: string, weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string, room_id: ?string, extra_group_ids: list<string>}
     */
    private static function slot(Body $body): array
    {
        $data = [
            'assignment_id' => (string) $body->string('assignment_id', 40),
            'weekday' => (int) $body->int('weekday', 1, 7),
            'start_time' => (string) self::time($body, 'start_time'),
            'end_time' => (string) self::time($body, 'end_time'),
            'valid_from' => (string) $body->date('valid_from'),
            'valid_to' => $body->date('valid_to', false),
            'room_id' => $body->string('room_id', 40, false),
            'extra_group_ids' => $body->strings('extra_group_ids', false, 10) ?? [],
        ];
        $body->done();

        return $data;
    }

    private static function time(Body $body, string $field, bool $required = true): ?string
    {
        $value = $body->string($field, 5, $required);
        if ($value !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            $body->error($field, 'Heure HH:MM attendue.');

            return null;
        }

        return $value;
    }
}
