<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Attendance\Application\AttendanceAccess;
use Scholaris\Modules\Socle\Attendance\Application\DisciplineService;
use Scholaris\Modules\Socle\Attendance\Http\Requests\IncidentRequest;
use Scholaris\Modules\Socle\Attendance\Http\Requests\SanctionRequest;
use Scholaris\Modules\Socle\Attendance\Http\Resources\Resource;
use Scholaris\Modules\Socle\Attendance\Infrastructure\DisciplineRepository;

/** Incidents et sanctions : le surveillant signale et propose, une autre personne valide (attendance.discipline.approve). */
final class DisciplineController extends AttendanceController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AttendanceAccess $access,
        private readonly DisciplineService $service,
        private readonly DisciplineRepository $repository,
    ) {
        parent::__construct($responder, $context, $access);
    }

    public function incidents(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE, AttendanceAccess::DISCIPLINE_APPROVE);
        $query = ListQuery::fromRequest($request, ['person_id', 'category']);
        [$rows, $next] = $this->repository->page(DisciplineRepository::INCIDENTS, $this->context->tenantId(), $this->context->establishmentId(), $query->filters, ['person_id' => 'person_id', 'category' => 'category'], $query);

        return $this->pageResponse($request, $rows, $next, Resource::incident());
    }

    /** Export CSV des incidents (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function exportIncidents(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE, AttendanceAccess::DISCIPLINE_APPROVE);
        $rows = $this->collectAll($request, ['person_id', 'category'], fn (ListQuery $query): array => $this->repository->page(DisciplineRepository::INCIDENTS, $this->context->tenantId(), $this->context->establishmentId(), $query->filters, ['person_id' => 'person_id', 'category' => 'category'], $query));
        $resource = Resource::incident();

        return $this->csv(
            'incidents.csv',
            ['Identifiant', 'Personne', 'Date', 'Catégorie', 'Description', 'Signalé par', 'Créé le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['person_id'], $r['occurred_on'], $r['category'], $r['description'], $r['reported_by'], $r['created_at']];
            }, $rows),
            $request,
        );
    }

    /** Export CSV des sanctions (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function exportSanctions(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE, AttendanceAccess::DISCIPLINE_APPROVE);
        $rows = $this->collectAll($request, ['person_id', 'status', 'kind'], fn (ListQuery $query): array => $this->repository->page(DisciplineRepository::SANCTIONS, $this->context->tenantId(), $this->context->establishmentId(), $query->filters, ['person_id' => 'person_id', 'status' => 'status', 'kind' => 'kind'], $query));
        $resource = Resource::sanction();

        return $this->csv(
            'sanctions.csv',
            ['Identifiant', 'Incident', 'Personne', 'Nature', 'Motif', 'Statut', 'Proposée par', 'Décidée par', 'Décidée le', 'Créée le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['incident_id'], $r['person_id'], $r['kind'], $r['reason'], $r['status'], $r['proposed_by'], $r['decided_by'], $r['decided_at'], $r['created_at']];
            }, $rows),
            $request,
        );
    }

    public function showIncident(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE, AttendanceAccess::DISCIPLINE_APPROVE);
        $row = $this->service->mustFind(DisciplineRepository::INCIDENTS, $this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::incident()->toArray($row)]);
    }

    public function storeIncident(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE);
        $data = (new IncidentRequest())->fromHttp($request);

        return $this->responder->json(['data' => Resource::incident()->toArray($this->service->reportIncident($principal, $this->context->establishmentId(), $data))], 201);
    }

    public function sanctions(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE, AttendanceAccess::DISCIPLINE_APPROVE);
        $query = ListQuery::fromRequest($request, ['person_id', 'status', 'kind']);
        [$rows, $next] = $this->repository->page(DisciplineRepository::SANCTIONS, $this->context->tenantId(), $this->context->establishmentId(), $query->filters, ['person_id' => 'person_id', 'status' => 'status', 'kind' => 'kind'], $query);

        return $this->pageResponse($request, $rows, $next, Resource::sanction());
    }

    public function showSanction(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE, AttendanceAccess::DISCIPLINE_APPROVE);
        $row = $this->service->mustFind(DisciplineRepository::SANCTIONS, $this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::sanction()->toArray($row)]);
    }

    public function storeSanction(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE);
        $data = (new SanctionRequest())->fromHttp($request);

        return $this->responder->json(['data' => Resource::sanction()->toArray($this->service->proposeSanction($principal, $this->context->establishmentId(), $data))], 201);
    }

    public function approveSanction(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::DISCIPLINE_APPROVE);

        return $this->responder->json(['data' => Resource::sanction()->toArray($this->service->decideSanction($principal, $this->context->establishmentId(), $this->param($request, 'id'), true))]);
    }

    public function rejectSanction(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::DISCIPLINE_APPROVE);

        return $this->responder->json(['data' => Resource::sanction()->toArray($this->service->decideSanction($principal, $this->context->establishmentId(), $this->param($request, 'id'), false))]);
    }
}
