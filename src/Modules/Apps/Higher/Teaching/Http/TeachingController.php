<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Teaching\Application\AssignmentService;
use Scholaris\Modules\Apps\Higher\Teaching\Application\SessionLogService;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeacherService;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingAccess;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingPermissions;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;

/**
 * Dossiers enseignants (EX-ENS-01), salles, affectations par le chef de département (EX-ENS-02), accords de dépassement (RG-ENS-4),
 * délégués de groupe (contre-signature, EX-ENS-05).
 */
final class TeachingController
{
    private const SCHOOL_DEPARTMENT = ['school' => 'school_id', 'department' => 'department_id'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly TeachingAccess $access,
        private readonly TeachingStore $store,
        private readonly TeacherService $teachers,
        private readonly AssignmentService $assignments,
        private readonly SessionLogService $logs,
    ) {
    }

    public function teachers(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::TEACHER_READ, TeachingAccess::selectedSchool($request));

        return $this->responder->json(Listing::of($request, $this->store, TeacherService::TABLE, $principal->tenantId, $establishmentId, ['teacher_status', 'department_id', 'school_id', 'person_id'], 'person_id', $this->access->sqlFilter($perimeter, self::SCHOOL_DEPARTMENT), TeachingResources::mapper('teacher')));
    }

    public function teacher(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $personId = self::arg($request, 'personId');
        $profile = $this->teachers->profile($principal->tenantId, $establishmentId, $personId) ?? throw HttpException::notFound('Dossier enseignant introuvable.');
        $me = $this->access->personOf($principal);
        if ($me !== $personId) {
            $this->access->assertReadable($this->access->require($principal, $establishmentId, TeachingPermissions::TEACHER_READ), array_intersect_key($profile, ['school_id' => 1, 'department_id' => 1]));
        }
        $assignments = $this->store->select(AssignmentService::TABLE, $principal->tenantId, $establishmentId, ['person_id' => $personId, 'status' => 'active'], 'element_code, group_id');

        return $this->responder->json(TeachingResources::of('teacher', $profile) + ['assignments' => array_map(TeachingResources::mapper('assignment'), $assignments)]);
    }

    public function saveTeacher(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $routePerson = $request->getAttribute('personId');
        $personId = is_string($routePerson) && $routePerson !== '' ? $routePerson : $body->string('person_id', 40);
        $data = ['person_id' => $personId];
        foreach (['department_id' => 40, 'grade' => 60, 'origin_establishment' => 160, 'employer_authorization_ref' => 80, 'employer_authorization_document' => 80] as $field => $max) {
            if ($body->has($field)) {
                $data[$field] = $body->string($field, $max, false);
            }
        }
        if ($body->has('teacher_status')) {
            $data['teacher_status'] = $body->choice('teacher_status', TeacherService::STATUSES);
        }
        if ($body->has('diplomas')) {
            $data['diplomas'] = $body->strings('diplomas', false, 20) ?? [];
        }
        if ($body->has('public_servant')) {
            $data['public_servant'] = $body->bool('public_servant');
        }
        if ($body->has('employer_authorization_until')) {
            $data['employer_authorization_until'] = $body->date('employer_authorization_until', false);
        }
        $body->done();
        [$principal, $establishmentId] = $this->context($request);
        $status = is_string($routePerson) ? 200 : 201;

        return $this->responder->json(TeachingResources::of('teacher', $this->teachers->save($principal, $establishmentId, $data)), $status);
    }

    public function rooms(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::TIMETABLE_READ, TeachingAccess::selectedSchool($request));

        return $this->responder->json(Listing::of($request, $this->store, TeacherService::ROOMS, $principal->tenantId, $establishmentId, ['school_id', 'active'], 'code', $this->access->sqlFilter($perimeter, ['school' => 'school_id']), TeachingResources::mapper('room')));
    }

    public function saveRoom(ServerRequestInterface $request): ResponseInterface
    {
        $routeId = $request->getAttribute('id');
        $id = is_string($routeId) && $routeId !== '' ? $routeId : null;
        $body = Body::of($request);
        $data = [
            'school_id' => $body->string('school_id', 40, $id === null),
            'code' => $body->string('code', 30, $id === null),
            'name' => $body->string('name', 120, $id === null),
            'capacity' => $body->int('capacity', 1, 5000, $id === null),
        ];
        if ($body->has('active')) {
            $data['active'] = $body->bool('active', true);
        }
        $body->done();
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(TeachingResources::of('room', $this->teachers->saveRoom($principal, $establishmentId, $id, $data)), $id === null ? 201 : 200);
    }

    public function assignmentsIndex(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $filters = ['curriculum_id', 'element_id', 'group_id', 'person_id', 'session_kind', 'status', 'academic_year_id', 'department_id'];
        $mine = ($request->getQueryParams()['mine'] ?? null) === '1';
        if ($mine) {
            $me = $this->access->personOf($principal) ?? throw HttpException::forbidden('Aucune personne liée à ce compte.');
            if (!$this->access->has($principal, $establishmentId, TeachingPermissions::LOG_WRITE)) {
                throw HttpException::forbidden('Permission requise : higher.session_log.write.');
            }
            $scope = ['person_id = ?', [$me]];
        } else {
            $scope = $this->access->sqlFilter($this->access->require($principal, $establishmentId, TeachingPermissions::ASSIGN_READ, TeachingAccess::selectedSchool($request)));
        }

        return $this->responder->json(Listing::of($request, $this->store, AssignmentService::TABLE, $principal->tenantId, $establishmentId, $filters, 'element_code, group_id, session_kind', $scope, TeachingResources::mapper('assignment')));
    }

    public function assignment(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $row = $this->assignments->readable($principal, $establishmentId, self::arg($request, 'id'));
        $overruns = $this->store->select(AssignmentService::OVERRUNS, $principal->tenantId, $establishmentId, ['assignment_id' => (string) $row['id']], 'approved_at');

        return $this->responder->json(TeachingResources::of('assignment', $row) + ['overrun_approvals' => array_map(TeachingResources::mapper('overrun'), $overruns)]);
    }

    public function assign(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $personId = (string) $body->string('person_id', 40);
        $curriculumId = (string) $body->string('curriculum_id', 40);
        $elementId = (string) $body->string('element_id', 40);
        $groupId = (string) $body->string('group_id', 40);
        $kind = (string) $body->choice('session_kind', TeachingPermissions::KINDS);
        $minutes = (int) $body->int('planned_minutes', 30, 60000);
        $body->done();
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(TeachingResources::of('assignment', $this->assignments->assign($principal, $establishmentId, $personId, $curriculumId, $elementId, $groupId, $kind, $minutes)), 201);
    }

    public function changeAssignment(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $minutes = $body->int('planned_minutes', 30, 60000, false);
        $personId = $body->string('person_id', 40, false);
        $body->done();
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(TeachingResources::of('assignment', $this->assignments->change($principal, $establishmentId, self::arg($request, 'id'), $minutes, $personId)));
    }

    public function endAssignment(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(TeachingResources::of('assignment', $this->assignments->end($principal, $establishmentId, self::arg($request, 'id'))));
    }

    public function approveOverrun(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $minutes = (int) $body->int('extra_minutes', 30, 60000);
        $document = (string) $body->string('document_ref', 80);
        $reason = (string) $body->string('reason', 500);
        $body->done();
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(TeachingResources::of('overrun', $this->assignments->approveOverrun($principal, $establishmentId, self::arg($request, 'id'), $minutes, $document, $reason)), 201);
    }

    public function delegates(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, array_merge(TeachingPermissions::DELEGATE_MANAGE, TeachingPermissions::LOG_READ), TeachingAccess::selectedSchool($request));

        return $this->responder->json(Listing::of($request, $this->store, SessionLogService::DELEGATES, $principal->tenantId, $establishmentId, ['group_id', 'person_id'], 'group_id', $this->access->sqlFilter($perimeter), TeachingResources::mapper('delegate')));
    }

    public function designateDelegate(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $groupId = (string) $body->string('group_id', 40);
        $personId = (string) $body->string('person_id', 40);
        $body->done();
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(TeachingResources::of('delegate', $this->logs->designateDelegate($principal, $establishmentId, $groupId, $personId)), 201);
    }

    public function removeDelegate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->logs->removeDelegate($principal, $establishmentId, self::arg($request, 'id'));

        return $this->responder->json(['deleted' => true]);
    }

    /** @return array{0: \Scholaris\Core\Security\Principal, 1: string} */
    private function context(ServerRequestInterface $request): array
    {
        return [TeachingAccess::principal($request), TeachingAccess::establishmentId($request)];
    }

    public static function arg(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || $value === '') {
            throw HttpException::notFound();
        }

        return $value;
    }
}
