<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\People\Application\AccessGuard;
use Scholaris\Modules\Socle\People\Application\EnrollmentService;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Domain\EnrollmentStatus;
use Scholaris\Modules\Socle\People\Http\Requests\Input;
use Scholaris\Modules\Socle\People\Http\Resources\Resource;
use Scholaris\Modules\Socle\People\Http\Resources\SimpleResources;

/** Inscriptions de l'établissement actif : brouillon continu, validation, changement de statut. */
final class EnrollmentController extends BaseController
{
    use ExportsList;

    private const EXPORT_LIMIT = 5000;

    private readonly Resource $resource;

    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AccessGuard $guard,
        PersonService $people,
        private readonly EnrollmentService $enrollments,
    ) {
        parent::__construct($responder, $context, $guard, $people);
        $this->resource = SimpleResources::enrollment();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $this->query($request, ['person_id', 'status', 'structure_id', 'academic_year_id']);
        [$rows, $next] = $this->enrollments->search($this->context->tenantId(), $this->context->establishmentId(), $query, $this->visibleScope($request));

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export CSV des inscriptions de l'établissement actif (mêmes filtres et visibilité que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->visibleScope($request);
        $rows = [];
        $cursor = null;
        do {
            $query = $this->query($this->exportPage($request, $cursor), ['person_id', 'status', 'structure_id', 'academic_year_id']);
            [$page, $cursor] = $this->enrollments->search($this->context->tenantId(), $this->context->establishmentId(), $query, $scope);
            foreach ($page as $row) {
                $rows[] = $this->resource->toArray($row);
            }
        } while ($cursor !== null && count($rows) < self::EXPORT_LIMIT);

        return $this->csvResponse(
            new Psr17Factory(),
            new Psr17Factory(),
            'inscriptions.csv',
            ['Identifiant', 'Personne', 'Classe', 'Année scolaire', 'Statut', 'Numéro interne', 'Complétude', 'Pièces dues le', 'Validée le', 'Créée le'],
            array_map(static fn (array $r): array => [
                $r['id'], $r['person_id'], $r['structure_id'], $r['academic_year_id'], $r['status'], $r['internal_number'],
                $r['completeness'], $r['documents_due_on'], $r['validated_at'], $r['created_at'],
            ], array_slice($rows, 0, self::EXPORT_LIMIT)),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->enrollments->get($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));
        $this->guard->requireView($this->principal($request), $this->context->establishmentId(), (string) $row['person_id']);

        return $this->ok(['data' => $this->resource->toArray($this->enrollments->refresh($this->context->tenantId(), (string) $row['id']))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());
        $data = Input::validate(
            Input::body($request),
            ['person_id' => Input::ref(), 'structure_id' => Input::ref(), 'academic_year_id' => Input::ref()],
            ['person_id'],
            ['structure_id', 'academic_year_id'],
        );
        $row = $this->enrollments->createDraft(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            (string) $data['person_id'],
            isset($data['structure_id']) ? (string) $data['structure_id'] : null,
            isset($data['academic_year_id']) ? (string) $data['academic_year_id'] : null,
            $this->principal($request)->userId,
        );
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'enrollment.draft_created', 'enrollment', (string) $row['id'], null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $before = $this->enrollments->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $changes = Input::validate(
            Input::body($request),
            ['structure_id' => Input::ref(), 'academic_year_id' => Input::ref()],
            [],
            ['structure_id', 'academic_year_id'],
            true,
        );
        $row = $this->enrollments->updateDraft($this->context->tenantId(), $this->context->establishmentId(), $id, $changes);
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'enrollment.draft_saved', 'enrollment', $id, $this->resource->toArray($before), $out);

        return $this->ok(['data' => $out]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $before = $this->enrollments->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->enrollments->deleteDraft($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->context->audit($request, 'enrollment.draft_deleted', 'enrollment', $id, $this->resource->toArray($before), null);

        return $this->noContent();
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $before = $this->enrollments->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $row = $this->enrollments->validate($this->context->tenantId(), $this->context->establishmentId(), $id);
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'enrollment.validated', 'enrollment', $id, $this->resource->toArray($before), $out);

        return $this->ok(['data' => $out]);
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $data = Input::validate(Input::body($request), ['status' => Input::enum(EnrollmentStatus::values())], ['status']);
        $before = $this->enrollments->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $row = $this->enrollments->changeStatus($this->context->tenantId(), $this->context->establishmentId(), $id, EnrollmentStatus::from((string) $data['status']));
        $out = $this->resource->toArray($row);
        $this->context->audit($request, 'enrollment.status_changed', 'enrollment', $id, $this->resource->toArray($before), $out);

        return $this->ok(['data' => $out]);
    }
}
