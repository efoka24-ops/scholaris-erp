<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Assessment\Application\RuleService;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;

/** Règles de calcul déclaratives, versionnées par année. */
final class RuleController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly RuleService $service,
        private readonly ResultRepository $repo,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['academic_year_id', 'structure_id']);
        [$rows, $next] = $this->repo->rulesPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::rules());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $row = $this->service->find($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::rules()->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::RULES))->fromHttp($request);
        $row = $this->service->publish($principal->tenantId, $this->context->establishmentId(), $principal->userId, $data);

        return $this->responder->json(['data' => Resource::rules()->toArray($row)], 201);
    }
}
