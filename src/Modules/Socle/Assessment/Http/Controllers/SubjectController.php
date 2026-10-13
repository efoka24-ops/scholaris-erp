<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Assessment\Application\AssessmentService;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;
use Scholaris\Modules\Socle\Assessment\Infrastructure\CatalogRepository;

/** Référentiel : matières, unités d'enseignement et leurs éléments (coefficients ou crédits). */
final class SubjectController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly AssessmentService $service,
        private readonly CatalogRepository $catalog,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['kind', 'active']);
        [$rows, $next] = $this->catalog->subjectPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::subject());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);

        return $this->one($this->service->subject($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::SUBJECT))->fromHttp($request);

        return $this->one($this->service->createSubject($this->context->tenantId(), $this->context->establishmentId(), $data), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::SUBJECT_UPDATE))->fromHttp($request, true);

        return $this->one($this->service->updateSubject($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'), $data));
    }

    public function addElement(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::ELEMENT))->fromHttp($request);

        return $this->one($this->service->addElement($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'), $data), 201);
    }

    /** @param array<string, mixed> $subject */
    private function one(array $subject, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => Resource::subject()->with($subject, ['elements' => Resource::element()])], $status);
    }
}
