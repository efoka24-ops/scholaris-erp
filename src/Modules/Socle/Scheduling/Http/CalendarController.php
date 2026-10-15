<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Scheduling\Application\AccessGuard;
use Scholaris\Modules\Socle\Scheduling\Application\CalendarService;
use Scholaris\Modules\Socle\Scheduling\Domain\CalendarKind;
use Scholaris\Modules\Socle\Scheduling\Http\Requests\Input;
use Scholaris\Modules\Socle\Scheduling\Http\Resources\Resource;
use Scholaris\Modules\Socle\Scheduling\Http\Resources\SchedulingResources;

/** Calendrier académique de l'établissement actif. */
final class CalendarController extends BaseController
{
    private const NULLABLE = ['ends_on', 'academic_year_id', 'structure_id', 'notes'];

    private readonly Resource $resource;

    public function __construct(JsonResponder $responder, RequestContext $context, AccessGuard $guard, private readonly CalendarService $calendar)
    {
        parent::__construct($responder, $context, $guard);
        $this->resource = SchedulingResources::event();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->scope($request);
        [$rows, $next] = $this->calendar->search(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->query($request, ['kind', 'academic_year_id', 'structure_id', 'from', 'to']),
        );

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export CSV du calendrier (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->scope($request);
        $rows = $this->collectAll(
            $request,
            ['kind', 'academic_year_id', 'structure_id', 'from', 'to'],
            fn (ListQuery $query): array => $this->calendar->search($this->context->tenantId(), $this->context->establishmentId(), $query),
        );
        $resource = $this->resource;

        return $this->csv(
            'calendrier.csv',
            ['Identifiant', 'Nature', 'Titre', 'Début', 'Fin', 'Année académique', 'Classe', 'Notes', 'Créé le', 'Modifié le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['kind'], $r['title'], $r['starts_on'], $r['ends_on'], $r['academic_year_id'], $r['structure_id'], $r['notes'], $r['created_at'], $r['updated_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->scope($request);

        return $this->ok(['data' => $this->resource->toArray($this->calendar->get($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $data = Input::validate(Input::body($request), $this->rules(), ['kind', 'title', 'starts_on'], self::NULLABLE);
        if (($data['ends_on'] ?? null) === null) {
            unset($data['ends_on']);
        }
        $row = $this->calendar->create($this->context->tenantId(), $this->context->establishmentId(), $data, $this->principal($request)->userId);
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'calendar_event.created', 'calendar_event', (string) $row['id'], null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $id = $this->param($request, 'id');
        $before = $this->calendar->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $changes = Input::validate(Input::body($request), $this->rules(), [], self::NULLABLE, true);
        if (array_key_exists('ends_on', $changes) && $changes['ends_on'] === null) {
            $changes['ends_on'] = $changes['starts_on'] ?? $before['starts_on'];
        }
        $row = $this->calendar->update($this->context->tenantId(), $this->context->establishmentId(), $id, $changes);
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'calendar_event.updated', 'calendar_event', $id, $this->resource->toArray($before), $out);

        return $this->ok(['data' => $out]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $id = $this->param($request, 'id');
        $before = $this->calendar->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->calendar->delete($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->context->audit($request, 'calendar_event.deleted', 'calendar_event', $id, $this->resource->toArray($before), null);

        return $this->noContent();
    }

    /** @return array<string, Closure(mixed): mixed> */
    private function rules(): array
    {
        return [
            'kind' => Input::enum(CalendarKind::values()),
            'title' => Input::text(160),
            'starts_on' => Input::date(),
            'ends_on' => Input::date(),
            'academic_year_id' => Input::ref(),
            'structure_id' => Input::ref(),
            'notes' => Input::text(500),
        ];
    }
}
