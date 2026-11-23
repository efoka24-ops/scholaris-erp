<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Assessment\Application\CorrectionService;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;
use Scholaris\Modules\Socle\Assessment\Infrastructure\GradeRepository;

/** Corrections de notes verrouillées : demande motivée, approbation par une autre personne. */
final class CorrectionController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly CorrectionService $service,
        private readonly GradeRepository $grades,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['status', 'assessment_id', 'person_id']);
        [$rows, $next] = $this->grades->correctionPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::correction());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $row = $this->service->find($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::correction()->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::ENTER, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::CORRECTION))->fromHttp($request);
        $row = $this->service->request($principal, $this->context->establishmentId(), $data);

        return $this->responder->json(['data' => Resource::correction()->toArray($row)], 202);
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, ...$this->service->approverPermissions($this->context->tenantId(), $this->context->establishmentId()));
        $row = $this->service->approve($principal, $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::correction()->toArray($row)]);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, ...$this->service->approverPermissions($this->context->tenantId(), $this->context->establishmentId()));
        $row = $this->service->reject($principal, $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::correction()->toArray($row)]);
    }
}
