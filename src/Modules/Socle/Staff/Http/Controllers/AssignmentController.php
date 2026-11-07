<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Staff\Application\AssignmentService;
use Scholaris\Modules\Socle\Staff\Application\AssignmentWriterService;
use Scholaris\Modules\Socle\Staff\Application\StaffAccess;
use Scholaris\Modules\Socle\Staff\Http\RequestContext;
use Scholaris\Modules\Socle\Staff\Http\Requests\Input;
use Scholaris\Modules\Socle\Staff\Http\Resources\Resource;
use Scholaris\Modules\Socle\Staff\Http\Resources\StaffResources;

/** Affectations d'un membre du personnel à des classes et matières, avec la charge horaire prévue. */
final class AssignmentController extends BaseController
{
    private readonly Resource $resource;

    public function __construct(JsonResponder $responder, RequestContext $context, StaffAccess $access, private readonly AssignmentService $assignments)
    {
        parent::__construct($responder, $context, $access);
        $this->resource = StaffResources::assignment();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $staffId = $this->param($request, 'id');
        $this->requireStaffView($request, $staffId);

        return $this->ok(['data' => $this->resource->collection($this->assignments->forStaff($this->context->tenantId(), $this->context->establishmentId(), $staffId))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireManage($principal, $this->context->establishmentId());
        $staffId = $this->param($request, 'id');
        $data = Input::validate(Input::body($request), $this->rules(), ['session_kind'], $this->nullable());
        $row = $this->resource->toArray($this->assignments->create($this->context->tenantId(), $this->context->establishmentId(), $staffId, $data, $principal->userId));
        $this->context->audit($request, 'staff.assignment_created', 'staff_assignment', (string) $row['id'], null, $row);

        return $this->ok(['data' => $row], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireManage($this->principal($request), $this->context->establishmentId());
        $staffId = $this->param($request, 'id');
        $id = $this->param($request, 'assignmentId');
        $changes = Input::validate(
            Input::body($request),
            $this->rules() + ['status' => Input::enum(['active', 'ended'])],
            [],
            $this->nullable(),
            true,
        );
        $before = $this->resource->toArray($this->assignments->get($this->context->tenantId(), $this->context->establishmentId(), $staffId, $id));
        $row = $this->resource->toArray($this->assignments->update($this->context->tenantId(), $this->context->establishmentId(), $staffId, $id, $changes));
        $this->context->audit($request, 'staff.assignment_updated', 'staff_assignment', $id, $before, $row);

        return $this->ok(['data' => $row]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireManage($this->principal($request), $this->context->establishmentId());
        $row = $this->assignments->delete($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'), $this->param($request, 'assignmentId'));
        $this->context->audit($request, 'staff.assignment_deleted', 'staff_assignment', (string) $row['id'], $this->resource->toArray($row), null);

        return $this->noContent();
    }

    private function requireStaffView(ServerRequestInterface $request, string $staffId): void
    {
        $principal = $this->principal($request);
        if (!$this->access->hasFullRead($principal, $this->context->establishmentId()) && !$this->access->isOwn($principal, $staffId)) {
            throw HttpException::notFound('Fiche du personnel introuvable.');
        }
    }

    /** @return array<string, \Closure(mixed): mixed> */
    private function rules(): array
    {
        return AssignmentWriterService::rules();
    }

    /** @return list<string> */
    private function nullable(): array
    {
        return AssignmentWriterService::NULLABLE;
    }
}
