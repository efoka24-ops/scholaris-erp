<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Attendance\Application\AttendanceAccess;
use Scholaris\Modules\Apps\Higher\Attendance\Application\AttendancePermissions;
use Scholaris\Modules\Apps\Higher\Attendance\Application\DisciplineService;
use Scholaris\Modules\Apps\Higher\Attendance\Infrastructure\AttendanceStore;

/** Sanctions et conseil de discipline (EX-PRE-04, RG-PRE-2), notification DOC-31. */
final class DisciplineController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly AttendanceAccess $access,
        private readonly AttendanceStore $store,
        private readonly DisciplineService $discipline,
    ) {
    }

    public function sanctions(ServerRequestInterface $request): ResponseInterface
    {
        $principal = AttendanceAccess::principal($request);
        $establishmentId = AttendanceAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, array_merge(AttendancePermissions::DISCIPLINE_READ, AttendancePermissions::READ), AttendanceAccess::selectedSchool($request));

        return $this->responder->json(Listing::of($request, $this->store, DisciplineService::SANCTIONS, $principal->tenantId, $establishmentId, ['person_id', 'sanction_kind', 'status', 'council_id'], 'decided_at DESC, id', $this->access->sqlFilter($perimeter), AttendanceResources::of(...)));
    }

    public function sanction(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $personId = (string) $body->string('person_id', 40);
        $enrolmentId = $body->string('enrolment_id', 40, false);
        $kind = (string) $body->choice('sanction_kind', AttendancePermissions::SANCTION_KINDS);
        $reason = (string) $body->string('reason', 1000);
        $starts = $body->date('starts_on', false);
        $ends = $body->date('ends_on', false);
        $body->done();

        return $this->responder->json(AttendanceResources::of($this->discipline->sanction(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), $personId, $enrolmentId, $kind, $reason, $starts, $ends)), 201);
    }

    public function notify(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->discipline->notify(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), AssiduityController::arg($request, 'id')), 201);
    }

    public function councils(ServerRequestInterface $request): ResponseInterface
    {
        $principal = AttendanceAccess::principal($request);
        $establishmentId = AttendanceAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, AttendancePermissions::DISCIPLINE_READ, AttendanceAccess::selectedSchool($request));

        return $this->responder->json(Listing::of($request, $this->store, DisciplineService::COUNCILS, $principal->tenantId, $establishmentId, ['person_id', 'status', 'decision'], 'hearing_date DESC, id', $this->access->sqlFilter($perimeter), AttendanceResources::of(...)));
    }

    public function council(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(AttendanceResources::of($this->discipline->readableCouncil(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), AssiduityController::arg($request, 'id'))));
    }

    public function convene(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $personId = (string) $body->string('person_id', 40);
        $enrolmentId = $body->string('enrolment_id', 40, false);
        $reason = (string) $body->string('reason', 1000);
        $incident = $body->string('incident_ref', 80, false);
        $hearing = (string) $body->date('hearing_date');
        $members = $body->strings('members', true, 15) ?? [];
        $body->done();

        return $this->responder->json(AttendanceResources::of($this->discipline->convene(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), $personId, $enrolmentId, $reason, $incident, $hearing, $members)), 201);
    }

    public function hearing(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $minutes = (string) $body->string('minutes_text', 20000);
        $decision = (string) $body->choice('decision', DisciplineService::DECISIONS);
        $reason = (string) $body->string('decision_reason', 1000);
        $from = $body->date('exclusion_from', false);
        $to = $body->date('exclusion_to', false);
        $body->done();

        return $this->responder->json(AttendanceResources::of($this->discipline->hearing(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), AssiduityController::arg($request, 'id'), $minutes, $decision, $reason, $from, $to)));
    }

    public function sign(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(AttendanceResources::of($this->discipline->sign(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), AssiduityController::arg($request, 'id'))));
    }
}
