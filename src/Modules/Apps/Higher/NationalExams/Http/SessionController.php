<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\EligibilityChecker;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\ListJournal;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\NationalExamsAccess as Access;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\NationalExamsPermissions;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\ResultService;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\SessionService;
use Scholaris\Modules\Apps\Higher\NationalExams\Domain\CandidateLifecycle;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamsStore;

/** Sessions d'examen national (EX-EXN-01), éligibilité d'un étudiant, journal de la liste, statistiques et taux de réussite. */
final class SessionController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly NationalExamsStore $store,
        private readonly SessionService $sessions,
        private readonly EligibilityChecker $eligibility,
        private readonly ResultService $results,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Access::principal($request);
        $establishmentId = Access::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, NationalExamsPermissions::READ, Access::selectedSchool($request));

        return $this->responder->json(NationalResources::listing($request, $this->store, 'session', SessionService::TABLE, $principal->tenantId, $establishmentId, ['program_id', 'academic_year_id', 'exam_type', 'status'], [], 'academic_year_id, specialty_label, id', $this->access->sqlFilter($perimeter)));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$session] = $this->sessions->readable(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'));

        return $this->responder->json(NationalResources::of('session', $session));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $data = [
            'program_id' => $body->string('program_id', 40), 'academic_year_id' => $body->string('academic_year_id', 40),
            'exam_type' => $body->choice('exam_type', CandidateLifecycle::EXAM_TYPES), 'attendance_from' => $body->date('attendance_from'),
            'attendance_to' => $body->date('attendance_to'), 'specialty_label' => $body->string('specialty_label', 160, false),
            'calendar_ref' => $body->string('calendar_ref', 120, false), 'registration_deadline' => $body->date('registration_deadline', false),
            'exam_starts_on' => $body->date('exam_starts_on', false), 'fee_xaf' => $body->int('fee_xaf', 0, 10_000_000, false) ?? 0,
            'required_pieces' => $body->strings('required_pieces', false, 30) ?? [],
        ];
        $body->done();

        return $this->responder->json(NationalResources::of('session', $this->sessions->create(Access::principal($request), Access::establishmentId($request), array_filter($data, static fn ($v): bool => $v !== null))), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $changes = [];
        foreach (['specialty_label' => 160, 'calendar_ref' => 120] as $field => $max) {
            if ($body->has($field)) {
                $changes[$field] = $body->string($field, $max);
            }
        }
        foreach (['registration_deadline', 'exam_starts_on', 'attendance_from', 'attendance_to'] as $field) {
            if ($body->has($field)) {
                $changes[$field] = $body->date($field);
            }
        }
        if ($body->has('fee_xaf')) {
            $changes['fee_xaf'] = $body->int('fee_xaf', 0, 10_000_000);
        }
        if ($body->has('required_pieces')) {
            $changes['required_pieces'] = $body->strings('required_pieces', true, 30);
        }
        if ($body->has('status')) {
            $changes['status'] = $body->choice('status', [CandidateLifecycle::SESSION_CLOSED]);
        }
        if ($changes === []) {
            $body->error('specialty_label', 'Aucun champ à modifier.');
        }
        $body->done();

        return $this->responder->json(NationalResources::of('session', $this->sessions->update(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'), $changes)));
    }

    public function eligibility(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Access::principal($request);
        [$session] = $this->sessions->readable($principal, Access::establishmentId($request), NationalResources::arg($request, 'id'));
        $personId = $request->getQueryParams()['person_id'] ?? null;
        if (!is_string($personId) || $personId === '') {
            throw HttpException::validation(['person_id' => ['Étudiant attendu (person_id).']]);
        }

        return $this->responder->json($this->eligibility->report($principal->tenantId, Access::establishmentId($request), $session, $personId));
    }

    public function events(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Access::principal($request);
        $establishmentId = Access::establishmentId($request);
        $id = NationalResources::arg($request, 'id');
        $this->sessions->readable($principal, $establishmentId, $id);

        return $this->responder->json(NationalResources::listing($request, $this->store, 'event', ListJournal::TABLE, $principal->tenantId, $establishmentId, ['event_kind'], ['session_id' => $id], 'created_at DESC, id DESC', ['1 = 1', []]));
    }

    public function statistics(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->results->statistics(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id')));
    }

    public function successRates(ServerRequestInterface $request): ResponseInterface
    {
        $year = $request->getQueryParams()['academic_year_id'] ?? null;

        return $this->responder->json(['data' => $this->results->successRates(Access::principal($request), Access::establishmentId($request), is_string($year) && $year !== '' ? $year : null)]);
    }
}
