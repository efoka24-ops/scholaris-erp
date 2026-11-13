<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Assessment\Application\CouncilService;
use Scholaris\Modules\Socle\Assessment\Application\ReportCardService;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;

/** Conseil de classe : proposition calculée, décision motivée, procès-verbal, file des bulletins. */
final class CouncilController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly CouncilService $service,
        private readonly ReportCardService $reportCards,
        private readonly ResultRepository $repo,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::READ, AssessmentPolicy::COUNCIL);
        $query = ListQuery::fromRequest($request, ['structure_id', 'period_id', 'status']);
        [$rows, $next] = $this->repo->councilPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::council());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::READ, AssessmentPolicy::COUNCIL);

        return $this->one($this->service->find($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::COUNCIL);
        $data = (new AssessmentRequest(AssessmentRequest::COUNCIL))->fromHttp($request);

        return $this->one($this->service->open($principal, $this->context->establishmentId(), $data), 201);
    }

    public function updateLine(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::COUNCIL);
        $data = (new AssessmentRequest(AssessmentRequest::COUNCIL_LINE))->fromHttp($request, true);

        return $this->one($this->service->updateLine(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->param($request, 'id'),
            $this->param($request, 'line_id'),
            $data,
        ));
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::COUNCIL);

        return $this->one($this->service->refresh($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')));
    }

    /** « Valider les décisions et générer le PV ». */
    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::COUNCIL);

        return $this->one($this->service->validate($principal, $this->context->establishmentId(), $this->param($request, 'id')));
    }

    public function reportCards(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::READ, AssessmentPolicy::COUNCIL);
        $rows = $this->reportCards->queue($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::reportCard()->collection($rows)]);
    }

    public function generateReportCards(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::COUNCIL);
        $rows = $this->reportCards->generate($principal, $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::reportCard()->collection($rows)]);
    }

    /** @param array<string, mixed> $council */
    private function one(array $council, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => Resource::council()->with($council, ['lines' => Resource::councilLine()])], $status);
    }
}
