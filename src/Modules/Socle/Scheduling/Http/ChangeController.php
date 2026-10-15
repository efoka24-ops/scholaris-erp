<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Scheduling\Application\AccessGuard;
use Scholaris\Modules\Socle\Scheduling\Application\ChangeService;
use Scholaris\Modules\Socle\Scheduling\Application\SchedulingConflict;
use Scholaris\Modules\Socle\Scheduling\Domain\ChangeKind;
use Scholaris\Modules\Socle\Scheduling\Http\Requests\Input;
use Scholaris\Modules\Socle\Scheduling\Http\Resources\Resource;
use Scholaris\Modules\Socle\Scheduling\Http\Resources\SchedulingResources;

/** Modifications ponctuelles : cours annulé, déplacé ou remplacé ; les concernés sont notifiés. */
final class ChangeController extends BaseController
{
    private readonly Resource $resource;

    public function __construct(JsonResponder $responder, RequestContext $context, AccessGuard $guard, private readonly ChangeService $changes)
    {
        parent::__construct($responder, $context, $guard);
        $this->resource = SchedulingResources::change();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->scope($request);
        [$rows, $next] = $this->changes->search(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->query($request, ['slot_id', 'kind', 'on_date']),
            $scope,
        );

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export CSV des modifications ponctuelles (mêmes filtres et même périmètre de lecture que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->scope($request);
        $rows = $this->collectAll(
            $request,
            ['slot_id', 'kind', 'on_date'],
            fn (ListQuery $query): array => $this->changes->search($this->context->tenantId(), $this->context->establishmentId(), $query, $scope),
        );
        $resource = $this->resource;

        return $this->csv(
            'modifications-edt.csv',
            ['Identifiant', 'Créneau', 'Date', 'Nature', 'Nouvelle date', 'Nouveau début', 'Nouvelle fin', 'Nouvelle salle', 'Nouvel enseignant', 'Motif', 'Personnes notifiées', 'Créée le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['slot_id'], $r['on_date'], $r['kind'], $r['new_date'], $r['new_start_time'], $r['new_end_time'], $r['new_room_id'], $r['new_teacher_person_id'], $r['reason'], $r['notified_count'], $r['created_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->scope($request);
        $row = $this->changes->get($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'), $scope);

        return $this->ok(['data' => $this->resource->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $data = Input::validate(
            Input::body($request),
            [
                'slot_id' => Input::ref(),
                'on_date' => Input::date(),
                'kind' => Input::enum(ChangeKind::values()),
                'new_date' => Input::date(),
                'new_start_time' => Input::time(),
                'new_end_time' => Input::time(),
                'new_room_id' => Input::ref(),
                'new_teacher_person_id' => Input::ref(),
                'reason' => Input::text(300),
            ],
            ['slot_id', 'on_date', 'kind'],
            ['new_date', 'new_start_time', 'new_end_time', 'new_room_id', 'new_teacher_person_id', 'reason'],
        );
        try {
            $row = $this->changes->create($this->context->tenantId(), $this->context->establishmentId(), $data, $this->principal($request)->userId);
        } catch (SchedulingConflict $e) {
            return $this->conflict($request, $e);
        }
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'timetable_change.created', 'timetable_change', (string) $row['id'], null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $id = $this->param($request, 'id');
        $before = $this->changes->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->changes->delete($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->context->audit($request, 'timetable_change.deleted', 'timetable_change', $id, $this->resource->toArray($before), null);

        return $this->noContent();
    }
}
