<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Billing\Application\ReconciliationService;
use Scholaris\Modules\Socle\Billing\Http\Requests\ReconciliationRequest;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\ReconciliationRepository;

/** Rapprochement mobile money par import CSV du relevé de l'opérateur. */
final class ReconciliationController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly ReconciliationService $service,
        private readonly ReconciliationRepository $repository,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, BillingPolicy::MANAGE);
        $query = ListQuery::fromRequest($request);
        [$rows, $next] = $this->repository->page($this->context->tenantId(), $this->context->establishmentId(), $query);

        return $this->pageResponse($request, $rows, $next, Resource::reconciliation());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, BillingPolicy::MANAGE);

        return $this->responder->json(['data' => Resource::reconciliationDetail(
            $this->service->detail($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')),
        )]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::MANAGE);
        $data = (new ReconciliationRequest())->fromHttp($request);
        $result = $this->service->import(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $principal->userId,
            (string) $data['channel'],
            (string) $data['source'],
            (string) $data['csv'],
        );

        return $this->responder->json(['data' => Resource::reconciliationDetail($result)], 201);
    }
}
