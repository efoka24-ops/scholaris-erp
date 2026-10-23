<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Staff\Application\HoursService;
use Scholaris\Modules\Socle\Staff\Application\StaffAccess;
use Scholaris\Modules\Socle\Staff\Domain\SessionKind;
use Scholaris\Modules\Socle\Staff\Http\RequestContext;
use Scholaris\Modules\Socle\Staff\Http\Requests\Input;
use Scholaris\Modules\Socle\Staff\Http\Resources\Resource;
use Scholaris\Modules\Socle\Staff\Http\Resources\StaffResources;

/** Heures réalisées : saisie, émargement de l'enseignant, validation ou rejet par le responsable. */
final class HoursController extends BaseController
{
    private readonly Resource $resource;

    public function __construct(JsonResponder $responder, RequestContext $context, StaffAccess $access, private readonly HoursService $hours)
    {
        parent::__construct($responder, $context, $access);
        $this->resource = StaffResources::hour();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$rows, $next] = $this->hours->search(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->query($request, ['staff_id', 'status', 'source', 'assignment_id', 'period']),
            $this->vacationsScope($request),
        );

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export CSV des heures réalisées (mêmes filtres et même visibilité que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->vacationsScope($request);
        $rows = $this->collectAll(
            $request,
            ['staff_id', 'status', 'source', 'assignment_id', 'period'],
            fn (ListQuery $query): array => $this->hours->search($this->context->tenantId(), $this->context->establishmentId(), $query, $scope),
        );
        $resource = $this->resource;

        return $this->csv(
            'heures.csv',
            ['Identifiant', 'Personnel', 'Date', 'Minutes', 'Nature', 'Contenu', 'Statut', 'Origine', 'Déclarée par', 'Signée le', 'Validée par', 'État de vacations'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['staff_id'], $r['held_on'], $r['minutes'], $r['session_kind'], $r['content'], $r['status'], $r['source'], $r['declared_by'], $r['teacher_signed_at'], $r['validated_by'], $r['statement_id']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->hours->get($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));
        $this->requireVacationsView($request, (string) $row['staff_id'], 'Heure introuvable.');

        return $this->ok(['data' => $this->resource->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        // A1 : droit contrôlé avant la validation du corps. Il faut être soi-même membre du personnel (déclaration de ses
        // propres heures) ou gérer les vacations ; le contrôle fin (heures d'un autre) suit.
        if ($this->access->ownStaffIds($principal) === [] && !$this->access->can($principal, StaffAccess::VACATIONS_MANAGE, $this->context->establishmentId())) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de déclarer des heures de vacation.');
        }
        $data = Input::validate(Input::body($request), [
            'staff_id' => Input::ref(), 'held_on' => Input::date(), 'minutes' => Input::int(1, HoursService::MAX_MINUTES),
            'session_kind' => Input::enum(SessionKind::values()), 'assignment_id' => Input::ref(), 'slot_id' => Input::ref(),
            'content' => Input::text(500), 'signed' => Input::bool(), 'level' => Input::text(40),
        ], ['staff_id', 'held_on', 'minutes'], ['assignment_id', 'slot_id', 'content', 'level']);
        $staffId = (string) $data['staff_id'];
        $own = $this->access->isOwn($principal, $staffId);
        if (!$own) {
            $this->access->requireVacationsManage($principal, $this->context->establishmentId());
        }
        $signed = ($data['signed'] ?? false) === true;
        if ($signed && !$own) {
            throw HttpException::forbidden('Seul l\'enseignant peut émarger ses propres séances.');
        }
        unset($data['signed'], $data['staff_id']);
        $row = $this->resource->toArray($this->hours->declare($this->context->tenantId(), $this->context->establishmentId(), $staffId, $data, $principal->userId, $signed));
        $this->context->audit($request, 'vacation_hours.declared', 'vacation_hours', (string) $row['id'], null, $row);

        return $this->ok(['data' => $row], 201);
    }

    public function sign(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $id = $this->param($request, 'id');
        $current = $this->hours->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->requireVacationsView($request, (string) $current['staff_id'], 'Heure introuvable.');
        if (!$this->access->isOwn($principal, (string) $current['staff_id'])) {
            throw HttpException::forbidden('Seul l\'enseignant peut émarger ses propres séances.');
        }
        $data = Input::validate(
            trim((string) $request->getBody()) === '' ? [] : Input::body($request),
            ['content' => Input::text(500), 'session_kind' => Input::enum(SessionKind::values())],
        );
        $row = $this->resource->toArray($this->hours->sign($this->context->tenantId(), $this->context->establishmentId(), $id, $data));
        $this->context->audit($request, 'vacation_hours.signed', 'vacation_hours', $id, $this->resource->toArray($current), $row);

        return $this->ok(['data' => $row]);
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireValidate($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $before = $this->resource->toArray($this->hours->get($this->context->tenantId(), $this->context->establishmentId(), $id));
        $row = $this->resource->toArray($this->hours->validate($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'vacation_hours.validated', 'vacation_hours', $id, $before, $row);

        return $this->ok(['data' => $row]);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireValidate($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $data = Input::validate(Input::body($request), ['reason' => Input::text(255)], ['reason']);
        $before = $this->resource->toArray($this->hours->get($this->context->tenantId(), $this->context->establishmentId(), $id));
        $row = $this->resource->toArray($this->hours->reject($this->context->tenantId(), $this->context->establishmentId(), $id, (string) $data['reason'], $principal->userId));
        $this->context->audit($request, 'vacation_hours.rejected', 'vacation_hours', $id, $before, $row);

        return $this->ok(['data' => $row]);
    }
}
