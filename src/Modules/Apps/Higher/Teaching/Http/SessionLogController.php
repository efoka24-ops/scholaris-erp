<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Teaching\Application\SessionLogService;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingAccess;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingPermissions;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;

/** Cahier de textes et émargement (API-08, EX-ENS-04/05), validation mensuelle des heures par le chef de département (EX-ENS-06). */
final class SessionLogController
{
    private const FILTERS = ['assignment_id', 'element_id', 'group_id', 'person_id', 'status', 'held_on'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly TeachingAccess $access,
        private readonly TeachingStore $store,
        private readonly SessionLogService $logs,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = TeachingAccess::principal($request);
        $establishmentId = TeachingAccess::establishmentId($request);
        if (($request->getQueryParams()['mine'] ?? null) === '1') {
            $me = $this->access->personOf($principal) ?? throw HttpException::forbidden('Aucune personne liée à ce compte.');
            $delegated = array_column($this->store->select(SessionLogService::DELEGATES, $principal->tenantId, $establishmentId, ['person_id' => $me]), 'group_id');
            $groups = $delegated === [] ? '' : ' OR group_id IN (' . implode(', ', array_fill(0, count($delegated), '?')) . ')';
            $scope = ['(person_id = ?' . $groups . ')', array_merge([$me], array_map('strval', $delegated))];
        } else {
            $scope = $this->access->sqlFilter($this->access->require($principal, $establishmentId, array_merge(TeachingPermissions::LOG_READ, TeachingPermissions::COUNTERSIGN), TeachingAccess::selectedSchool($request)));
        }

        return $this->responder->json(Listing::of($request, $this->store, SessionLogService::TABLE, $principal->tenantId, $establishmentId, self::FILTERS, 'held_on DESC, start_time DESC, id', $scope, TeachingResources::mapper('log')));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(TeachingResources::of('log', $this->logs->readable(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'))));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $data = [
            'assignment_id' => (string) $body->string('assignment_id', 40),
            'entry_id' => $body->string('entry_id', 40, false),
            'held_on' => (string) $body->date('held_on'),
            'start_time' => (string) $body->string('start_time', 5),
            'end_time' => (string) $body->string('end_time', 5),
            'content' => $body->string('content', 5000, false),
            'homework' => $body->string('homework', 2000, false),
            'attendance_session_id' => $body->string('attendance_session_id', 40, false),
        ];
        foreach (['start_time', 'end_time'] as $field) {
            if ($data[$field] !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $data[$field]) !== 1) {
                $body->error($field, 'Heure HH:MM attendue.');
            }
        }
        $body->done();

        return $this->responder->json(TeachingResources::of('log', $this->logs->create(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), $data)), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $content = $body->string('content', 5000, false);
        $homework = $body->string('homework', 2000, false);
        $body->done();

        return $this->responder->json(TeachingResources::of('log', $this->logs->update(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'), $content, $homework)));
    }

    public function sign(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(TeachingResources::of('log', $this->logs->sign(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'))));
    }

    public function countersign(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(TeachingResources::of('log', $this->logs->countersign(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'))));
    }

    public function hours(ServerRequestInterface $request): ResponseInterface
    {
        $month = self::month($request->getQueryParams()['month'] ?? null);

        return $this->responder->json(['data' => $this->logs->monthSummary(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), $month, TeachingAccess::selectedSchool($request))]);
    }

    public function validations(ServerRequestInterface $request): ResponseInterface
    {
        $principal = TeachingAccess::principal($request);
        $establishmentId = TeachingAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::LOG_READ, TeachingAccess::selectedSchool($request));

        return $this->responder->json(Listing::of($request, $this->store, SessionLogService::VALIDATIONS, $principal->tenantId, $establishmentId, ['person_id', 'period_month', 'department_id'], 'period_month DESC, person_id', $this->access->sqlFilter($perimeter, ['school' => 'school_id', 'department' => 'department_id']), TeachingResources::mapper('validation')));
    }

    public function validateMonth(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $personId = (string) $body->string('person_id', 40);
        $month = $body->string('month', 7);
        $body->done();
        $result = $this->logs->validateMonth(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), $personId, self::month($month));

        return $this->responder->json([
            'validations' => array_map(TeachingResources::mapper('validation'), $result['validations']),
            'pending' => $result['pending'],
        ], 201);
    }

    private static function month(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            throw HttpException::validation(['month' => ['Mois AAAA-MM attendu.']]);
        }

        return $value;
    }
}
