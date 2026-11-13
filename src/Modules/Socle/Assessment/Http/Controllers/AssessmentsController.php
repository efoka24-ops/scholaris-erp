<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Assessment\Application\AssessmentService;
use Scholaris\Modules\Socle\Assessment\Application\AssessmentSettings;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;
use Scholaris\Modules\Socle\Assessment\Infrastructure\CatalogRepository;

/** Épreuves pondérées et leur cycle de vie (états déclarés par l'application). */
final class AssessmentsController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly AssessmentService $service,
        private readonly CatalogRepository $catalog,
        private readonly AssessmentSettings $settings,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['subject_id', 'structure_id', 'period_id', 'status', 'kind']);
        [$rows, $next] = $this->catalog->assessmentPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::assessment());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $row = $this->service->assessment($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::assessment()->toArray($row) + [
            'lifecycle' => $this->settings->lifecycle($this->context->tenantId(), $this->context->establishmentId())->toArray(),
            'qualitative_scale' => $this->settings->qualitativeScale($this->context->tenantId(), $this->context->establishmentId()),
        ]]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::ENTER, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::ASSESSMENT))->fromHttp($request);
        $row = $this->service->createAssessment($principal, $this->context->establishmentId(), $data);

        return $this->responder->json(['data' => Resource::assessment()->toArray($row)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::ENTER, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::ASSESSMENT_UPDATE))->fromHttp($request, true);
        $row = $this->service->updateAssessment($principal, $this->context->establishmentId(), $this->param($request, 'id'), $data);

        return $this->responder->json(['data' => Resource::assessment()->toArray($row)]);
    }

    public function transition(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorizeRead($request);
        $data = (new AssessmentRequest(AssessmentRequest::TRANSITION))->fromHttp($request);
        $row = $this->service->transition($principal, $this->context->establishmentId(), $this->param($request, 'id'), (string) $data['to']);

        return $this->responder->json(['data' => Resource::assessment()->toArray($row)]);
    }

    public function reveal(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::VALIDATE);
        $row = $this->service->reveal($principal, $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::assessment()->toArray($row)]);
    }
}
