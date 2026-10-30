<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Staff\Application\LeaveAccess;
use Scholaris\Modules\Socle\Staff\Application\LeaveService;
use Scholaris\Modules\Socle\Staff\Application\StaffAccess;
use Scholaris\Modules\Socle\Staff\Application\StaffClock;
use Scholaris\Modules\Socle\Staff\Domain\LeaveType;
use Scholaris\Modules\Socle\Staff\Http\RequestContext;
use Scholaris\Modules\Socle\Staff\Http\Requests\Input;
use Scholaris\Modules\Socle\Staff\Http\Resources\LeaveResources;
use Scholaris\Modules\Socle\Staff\Http\Resources\Resource;

/** Congés et absences : demandes, validation, soldes par type, calendrier d'absence. */
final class LeaveController extends BaseController
{
    private readonly Resource $resource;

    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        StaffAccess $access,
        private readonly LeaveAccess $leaveAccess,
        private readonly LeaveService $leaves,
        private readonly StaffClock $clock,
    ) {
        parent::__construct($responder, $context, $access);
        $this->resource = LeaveResources::request();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$rows, $next] = $this->leaves->search(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->query($request, ['staff_id', 'status', 'leave_type']),
            $this->leaveAccess->scope($this->principal($request), $this->context->establishmentId()),
        );

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export des congés (mêmes filtres et même visibilité que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->leaveAccess->scope($this->principal($request), $this->context->establishmentId());
        $rows = $this->collectAll(
            $request,
            ['staff_id', 'status', 'leave_type'],
            fn (ListQuery $query): array => $this->leaves->search($this->context->tenantId(), $this->context->establishmentId(), $query, $scope),
        );
        $resource = $this->resource;

        return $this->csv(
            'conges.csv',
            ['Identifiant', 'Personnel', 'Type', 'Début', 'Fin', 'Jours', 'Motif', 'Statut', 'Demandé par', 'Décidé par', 'Décidé le', 'Créé le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['staff_id'], $r['leave_type'], $r['starts_on'], $r['ends_on'], $r['days'], $r['reason'], $r['status'], $r['requested_by'], $r['decided_by'], $r['decided_at'], $r['created_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->leaves->get($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));
        $this->requireVisible($request, (string) $row['staff_id']);

        return $this->ok(['data' => $this->resource->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $establishmentId = $this->context->establishmentId();
        $this->leaveAccess->requireRequest($principal, $establishmentId);
        $data = Input::validate(Input::body($request), [
            'staff_id' => Input::ref(), 'leave_type' => Input::enum(LeaveType::values()),
            'starts_on' => Input::date(), 'ends_on' => Input::date(), 'reason' => Input::text(500),
        ], ['leave_type', 'starts_on', 'ends_on'], ['reason']);
        $own = $this->leaveAccess->ownStaffIds($principal);
        $staffId = isset($data['staff_id']) ? (string) $data['staff_id'] : ($own[0] ?? null);
        if ($staffId === null || !in_array($staffId, $own, true)) {
            throw HttpException::notFound('Fiche du personnel introuvable.');
        }
        unset($data['staff_id']);
        $row = $this->resource->toArray($this->leaves->request($this->context->tenantId(), $establishmentId, $staffId, $data, $principal->userId));
        $this->context->audit($request, 'leave_request.created', 'leave_request', (string) $row['id'], null, $row);

        return $this->ok(['data' => $row], 201);
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->leaveAccess->requireApprove($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $before = $this->resource->toArray($this->leaves->get($this->context->tenantId(), $this->context->establishmentId(), $id));
        $row = $this->resource->toArray($this->leaves->approve($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'leave_request.approved', 'leave_request', $id, $before, $row);

        return $this->ok(['data' => $row]);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->leaveAccess->requireApprove($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $before = $this->resource->toArray($this->leaves->get($this->context->tenantId(), $this->context->establishmentId(), $id));
        $row = $this->resource->toArray($this->leaves->reject($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'leave_request.rejected', 'leave_request', $id, $before, $row);

        return $this->ok(['data' => $row]);
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $id = $this->param($request, 'id');
        $before = $this->resource->toArray($this->leaves->get($this->context->tenantId(), $this->context->establishmentId(), $id));
        if (!$this->leaveAccess->isOwn($principal, (string) $before['staff_id'])) {
            throw HttpException::notFound('Demande de congé introuvable.');
        }
        $row = $this->resource->toArray($this->leaves->cancel($this->context->tenantId(), $this->context->establishmentId(), $id));
        $this->context->audit($request, 'leave_request.cancelled', 'leave_request', $id, $before, $row);

        return $this->ok(['data' => $row]);
    }

    public function calendar(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $window = Input::validate(
            ['from' => $params['from'] ?? $this->clock->today(), 'to' => $params['to'] ?? $this->clock->inDays(30)],
            ['from' => Input::date(), 'to' => Input::date()],
            ['from', 'to'],
        );
        if ($window['to'] < $window['from']) {
            throw HttpException::validation(['to' => ['La date de fin doit suivre la date de début.']]);
        }
        $rows = $this->leaves->calendar(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            (string) $window['from'],
            (string) $window['to'],
            $this->leaveAccess->scope($this->principal($request), $this->context->establishmentId()),
        );

        return $this->ok(['data' => $this->resource->collection($rows), 'meta' => ['from' => $window['from'], 'to' => $window['to']]]);
    }

    public function balances(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $params = $request->getQueryParams();
        $year = isset($params['year']) ? filter_var($params['year'], FILTER_VALIDATE_INT) : (int) substr($this->clock->today(), 0, 4);
        if ($year === false || $year < 2000 || $year > 2100) {
            throw HttpException::validation(['year' => ['Année invalide.']]);
        }
        $own = $this->leaveAccess->ownStaffIds($principal);
        $staffId = isset($params['staff_id']) && is_string($params['staff_id']) ? $params['staff_id'] : ($own[0] ?? null);
        if ($staffId === null || (!in_array($staffId, $own, true) && !$this->leaveAccess->canSeeAll($principal, $this->context->establishmentId()))) {
            throw HttpException::notFound('Fiche du personnel introuvable.');
        }

        // A3 : enveloppe de liste paginée par curseur (une ligne par type de congé : liste courte, découpée en mémoire).
        $query = ListQuery::fromRequest($request);
        $rows = $this->leaves->balances($this->context->tenantId(), $this->context->establishmentId(), $staffId, $year);
        $offset = 0;
        if ($query->cursor !== null) {
            $decoded = ListQuery::decodeCursor($query->cursor);
            if ($decoded === null || preg_match('/^o:(\d{1,9})$/', $decoded, $match) !== 1) {
                throw HttpException::validation(['cursor' => ['Curseur invalide.']], 'Paramètres de liste invalides.');
            }
            $offset = (int) $match[1];
        }
        $next = $offset + $query->perPage < count($rows) ? ListQuery::encodeCursor('o:' . ($offset + $query->perPage)) : null;

        return $this->ok(['data' => array_slice($rows, $offset, $query->perPage), 'meta' => ['next_cursor' => $next]]);
    }

    public function putBalance(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->leaveAccess->requireManage($principal, $this->context->establishmentId());
        $data = Input::validate(Input::body($request), [
            'staff_id' => Input::ref(), 'leave_type' => Input::enum(LeaveType::values()),
            'leave_year' => Input::int(2000, 2100), 'entitled_days' => Input::int(0, 366),
        ], ['staff_id', 'leave_type', 'leave_year', 'entitled_days']);
        $rows = $this->leaves->setBalance(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            (string) $data['staff_id'],
            (string) $data['leave_type'],
            (int) $data['leave_year'],
            (int) $data['entitled_days'],
        );
        $this->context->audit($request, 'leave_balance.set', 'leave_balance', (string) $data['staff_id'], null, $data);

        return $this->ok(['data' => $rows]);
    }

    private function requireVisible(ServerRequestInterface $request, string $staffId): void
    {
        $principal = $this->principal($request);
        if (!$this->leaveAccess->canSeeAll($principal, $this->context->establishmentId()) && !$this->leaveAccess->isOwn($principal, $staffId)) {
            throw HttpException::notFound('Demande de congé introuvable.');
        }
    }
}
