<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Staff\Application\StaffAccess;
use Scholaris\Modules\Socle\Staff\Application\StaffService;
use Scholaris\Modules\Socle\Staff\Domain\StaffKind;
use Scholaris\Modules\Socle\Staff\Domain\StaffStatus;
use Scholaris\Modules\Socle\Staff\Http\RequestContext;
use Scholaris\Modules\Socle\Staff\Http\Requests\Input;
use Scholaris\Modules\Socle\Staff\Http\Resources\Resource;
use Scholaris\Modules\Socle\Staff\Http\Resources\StaffResources;

/** Fiches du personnel et qualifications. */
final class StaffController extends BaseController
{
    private readonly Resource $resource;
    private readonly Resource $qualification;

    public function __construct(JsonResponder $responder, RequestContext $context, StaffAccess $access, private readonly StaffService $staff)
    {
        parent::__construct($responder, $context, $access);
        $this->resource = StaffResources::staff();
        $this->qualification = StaffResources::qualification();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $scope = $this->access->hasFullRead($principal, $this->context->establishmentId()) ? null : $this->access->ownStaffIds($principal);
        [$rows, $next] = $this->staff->search($this->context->tenantId(), $this->context->establishmentId(), $this->query($request, ['kind', 'status', 'category', 'person_id']), $scope);

        return $this->page($request, $this->resource->collection($this->staff->withIdentity($this->context->tenantId(), $rows)), $next);
    }

    /** Export CSV du personnel (mêmes filtres et même visibilité que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $scope = $this->access->hasFullRead($principal, $this->context->establishmentId()) ? null : $this->access->ownStaffIds($principal);
        $rows = $this->collectAll(
            $request,
            ['kind', 'status', 'category', 'person_id'],
            fn (ListQuery $query): array => $this->staff->search($this->context->tenantId(), $this->context->establishmentId(), $query, $scope),
        );
        $resource = $this->resource;

        return $this->csv(
            'personnel.csv',
            ['Identifiant', 'Personne', 'Prénom', 'Nom', 'Téléphone', 'E-mail', 'Type', 'Statut', 'Grade', 'Catégorie', 'Matricule', 'Département', 'Embauché le', 'Sorti le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['person_id'], $r['first_name'], $r['last_name'], $r['phone'], $r['email'], $r['kind'], $r['status'], $r['grade'], $r['category'], $r['employee_number'], $r['department_id'], $r['hired_on'], $r['left_on']];
            }, $this->staff->withIdentity($this->context->tenantId(), $rows)),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ok(['data' => $this->resource->toArray($this->visible($request, $this->param($request, 'id')))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireManage($this->principal($request), $this->context->establishmentId());
        $data = Input::validate(Input::body($request), [
            'person_id' => Input::ref(), 'kind' => Input::enum(StaffKind::values()), 'grade' => Input::text(80), 'category' => Input::text(40),
            'employee_number' => Input::text(40), 'department_id' => Input::ref(), 'hired_on' => Input::date(),
        ], ['person_id', 'kind'], ['grade', 'category', 'employee_number', 'department_id', 'hired_on']);
        $row = $this->staff->create($this->context->tenantId(), $this->context->establishmentId(), $data);
        $out = $this->resource->toArray($this->staff->withIdentity($this->context->tenantId(), [$row])[0]);
        $this->context->audit($request, 'staff.created', 'staff_member', (string) $row['id'], null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $changes = Input::validate(Input::body($request), [
            'kind' => Input::enum(StaffKind::values()), 'status' => Input::enum(StaffStatus::values()), 'grade' => Input::text(80),
            'category' => Input::text(40), 'employee_number' => Input::text(40), 'department_id' => Input::ref(),
            'hired_on' => Input::date(), 'left_on' => Input::date(),
        ], [], ['grade', 'category', 'employee_number', 'department_id', 'hired_on', 'left_on'], true);
        $before = $this->resource->toArray($this->staff->get($this->context->tenantId(), $this->context->establishmentId(), $id));
        $row = $this->staff->update($this->context->tenantId(), $this->context->establishmentId(), $id, $changes);
        $out = $this->resource->toArray($this->staff->withIdentity($this->context->tenantId(), [$row])[0]);
        $this->context->audit($request, 'staff.updated', 'staff_member', $id, $before, $out);

        return $this->ok(['data' => $out]);
    }

    public function qualifications(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->visible($request, $id);

        return $this->ok(['data' => $this->qualification->collection($this->staff->qualifications($this->context->tenantId(), $this->context->establishmentId(), $id))]);
    }

    public function addQualification(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $data = Input::validate(
            Input::body($request),
            ['title' => Input::text(160), 'institution' => Input::text(160), 'obtained_on' => Input::date()],
            ['title'],
            ['institution', 'obtained_on'],
        );
        $row = $this->qualification->toArray($this->staff->addQualification($this->context->tenantId(), $this->context->establishmentId(), $id, $data));
        $this->context->audit($request, 'staff.qualification_added', 'staff_member', $id, null, $row);

        return $this->ok(['data' => $row], 201);
    }

    public function deleteQualification(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $row = $this->staff->deleteQualification($this->context->tenantId(), $this->context->establishmentId(), $id, $this->param($request, 'qualificationId'));
        $this->context->audit($request, 'staff.qualification_deleted', 'staff_member', $id, $this->qualification->toArray($row), null);

        return $this->noContent();
    }

    /**
     * Fiche lisible par l'appelant (droit de lecture, ou sa propre fiche) ; 404 sinon.
     *
     * @return array<string, mixed>
     */
    private function visible(ServerRequestInterface $request, string $id): array
    {
        $principal = $this->principal($request);
        $row = $this->staff->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        if (!$this->access->hasFullRead($principal, $this->context->establishmentId()) && !$this->access->isOwn($principal, $id)) {
            throw HttpException::notFound('Fiche du personnel introuvable.');
        }

        return $this->staff->withIdentity($this->context->tenantId(), [$row])[0];
    }
}
