<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Scheduling\Application\AccessGuard;
use Scholaris\Modules\Socle\Scheduling\Application\SchedulingConflict;
use Scholaris\Modules\Socle\Scheduling\Application\SlotService;
use Scholaris\Modules\Socle\Scheduling\Domain\Conflict;
use Scholaris\Modules\Socle\Scheduling\Http\Requests\Input;
use Scholaris\Modules\Socle\Scheduling\Http\Requests\SlotRules;
use Scholaris\Modules\Socle\Scheduling\Http\Resources\Resource;
use Scholaris\Modules\Socle\Scheduling\Http\Resources\SchedulingResources;

/** Créneaux hebdomadaires de l'établissement actif, avec détection des conflits (409) et vérification à blanc. */
final class SlotController extends BaseController
{
    private const REQUIRED = SlotRules::REQUIRED;
    private const NULLABLE = SlotRules::NULLABLE;

    private readonly Resource $resource;

    public function __construct(JsonResponder $responder, RequestContext $context, AccessGuard $guard, private readonly SlotService $slots)
    {
        parent::__construct($responder, $context, $guard);
        $this->resource = SchedulingResources::slot();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->scope($request);
        [$rows, $next] = $this->slots->search(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->query($request, ['teacher_person_id', 'room_id', 'structure_id', 'weekday', 'period_of_day', 'course_unit_id', 'session_kind']),
            $scope,
        );

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export CSV des créneaux (mêmes filtres et même périmètre de lecture que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->scope($request);
        $rows = $this->collectAll(
            $request,
            ['teacher_person_id', 'room_id', 'structure_id', 'weekday', 'period_of_day', 'course_unit_id', 'session_kind'],
            fn (ListQuery $query): array => $this->slots->search($this->context->tenantId(), $this->context->establishmentId(), $query, $scope),
        );
        $resource = $this->resource;

        return $this->csv(
            'creneaux.csv',
            ['Identifiant', 'Matière', 'Enseignant', 'Salle', 'Classes', 'Jour (1 = lundi)', 'Début', 'Fin', 'Période', 'Valide du', "Valide jusqu'au"],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['subject_label'], $r['teacher_person_id'], $r['room_id'], implode(',', (array) $r['structure_ids']), $r['weekday'], $r['start_time'], $r['end_time'], $r['period_of_day'], $r['valid_from'], $r['valid_to']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->scope($request);
        $row = $this->slots->get($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'), $scope);

        return $this->ok(['data' => $this->resource->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $data = Input::validate(Input::body($request), $this->rules(), self::REQUIRED, self::NULLABLE);
        try {
            $row = $this->slots->create($this->context->tenantId(), $this->context->establishmentId(), $data, $this->principal($request)->userId);
        } catch (SchedulingConflict $e) {
            return $this->conflict($request, $e);
        }
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'timetable_slot.created', 'timetable_slot', (string) $row['id'], null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $id = $this->param($request, 'id');
        $before = $this->slots->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $changes = Input::validate(Input::body($request), $this->rules(), [], self::NULLABLE, true);
        try {
            $row = $this->slots->update($this->context->tenantId(), $this->context->establishmentId(), $id, $changes);
        } catch (SchedulingConflict $e) {
            return $this->conflict($request, $e);
        }
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'timetable_slot.updated', 'timetable_slot', $id, $this->resource->toArray($before), $out);

        return $this->ok(['data' => $out]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $id = $this->param($request, 'id');
        $before = $this->slots->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->slots->delete($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->context->audit($request, 'timetable_slot.deleted', 'timetable_slot', $id, $this->resource->toArray($before), null);

        return $this->noContent();
    }

    /** Vérifie un emplacement sans l'enregistrer ; ignore_slot_id exclut un créneau existant (modification). */
    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $data = Input::validate(Input::body($request), $this->rules() + ['ignore_slot_id' => Input::ref()], self::REQUIRED, [...self::NULLABLE, 'ignore_slot_id']);
        $ignore = isset($data['ignore_slot_id']) ? (string) $data['ignore_slot_id'] : null;
        unset($data['ignore_slot_id']);
        $conflicts = $this->slots->check($this->context->tenantId(), $this->context->establishmentId(), $data, $ignore);

        return $this->ok(['data' => [
            'ok' => $conflicts === [],
            'conflicts' => array_map(static fn (Conflict $c): array => $c->toArray(), $conflicts),
        ]]);
    }

    /** @return array<string, Closure(mixed): mixed> */
    private function rules(): array
    {
        return SlotRules::rules();
    }
}
