<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Attendance\Application\AttendanceAccess;
use Scholaris\Modules\Socle\Attendance\Application\PickupService;
use Scholaris\Modules\Socle\Attendance\Http\Requests\PickupRequest;
use Scholaris\Modules\Socle\Attendance\Http\Resources\Resource;
use Scholaris\Modules\Socle\Attendance\Infrastructure\DisciplineRepository;

/** Personnes autorisées à récupérer un enfant (maternelle) : base minimale, l'application Maternelle viendra plus tard. */
final class PickupController extends AttendanceController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AttendanceAccess $access,
        private readonly PickupService $service,
        private readonly DisciplineRepository $repository,
    ) {
        parent::__construct($responder, $context, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::READ, AttendanceAccess::DISCIPLINE_MANAGE);
        $query = ListQuery::fromRequest($request, ['learner_id', 'status', 'grant_type']);
        [$rows, $next] = $this->repository->page(DisciplineRepository::PICKUPS, $this->context->tenantId(), $this->context->establishmentId(), $query->filters, ['learner_id' => 'learner_id', 'status' => 'status', 'grant_type' => 'grant_type'], $query);

        return $this->pageResponse($request, $rows, $next, Resource::pickup());
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE);
        $data = (new PickupRequest())->fromHttp($request);

        return $this->responder->json(['data' => Resource::pickup()->toArray($this->service->grant($principal, $this->context->establishmentId(), $data))], 201);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::DISCIPLINE_MANAGE);

        return $this->responder->json(['data' => Resource::pickup()->toArray($this->service->revoke($principal, $this->context->establishmentId(), $this->param($request, 'id')))]);
    }
}
