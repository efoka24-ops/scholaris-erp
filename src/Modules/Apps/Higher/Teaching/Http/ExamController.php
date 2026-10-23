<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Teaching\Application\ExamScheduleService;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingAccess;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingPermissions;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;

/** Planning des examens (EX-EDT-06, Should) : épreuves, publication, convocations DOC-13, liste d'émargement DOC-08. */
final class ExamController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly TeachingAccess $access,
        private readonly TeachingStore $store,
        private readonly ExamScheduleService $exams,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = TeachingAccess::principal($request);
        $establishmentId = TeachingAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::EXAM_READ, TeachingAccess::selectedSchool($request));

        return $this->responder->json(Listing::of($request, $this->store, ExamScheduleService::TABLE, $principal->tenantId, $establishmentId, ['group_id', 'element_id', 'exam_date', 'status', 'exam_session', 'academic_year_id'], 'exam_date, start_time, id', $this->access->sqlFilter($perimeter), TeachingResources::mapper('exam')));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(TeachingResources::of('exam', $this->exams->readable(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'))));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $data = [
            'curriculum_id' => (string) $body->string('curriculum_id', 40),
            'element_id' => (string) $body->string('element_id', 40),
            'group_id' => (string) $body->string('group_id', 40),
            'exam_session' => (string) $body->choice('exam_session', ExamScheduleService::SESSIONS, false, 'normal'),
            'exam_date' => (string) $body->date('exam_date'),
            'start_time' => (string) $body->string('start_time', 5),
            'end_time' => (string) $body->string('end_time', 5),
            'room_id' => $body->string('room_id', 40, false),
            'supervisors' => $body->strings('supervisors', false, 20) ?? [],
            'paper_ref' => $body->string('paper_ref', 80, false),
        ];
        foreach (['start_time', 'end_time'] as $field) {
            if ($data[$field] !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $data[$field]) !== 1) {
                $body->error($field, 'Heure HH:MM attendue.');
            }
        }
        $body->done();

        return $this->responder->json(TeachingResources::of('exam', $this->exams->create(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), $data)), 201);
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(TeachingResources::of('exam', $this->exams->setStatus(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'), 'published')));
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(TeachingResources::of('exam', $this->exams->setStatus(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id'), 'cancelled')));
    }

    public function convocations(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->exams->convocations(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id')), 201);
    }

    public function attendanceSheet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->exams->attendanceSheet(TeachingAccess::principal($request), TeachingAccess::establishmentId($request), TeachingController::arg($request, 'id')), 201);
    }
}
