<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Billing\Application\ReversalService;
use Scholaris\Modules\Socle\Billing\Http\Requests\ReversalRequest;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;

/** Annulations de paiement : demande motivée, validation par une autre personne. */
final class ReversalController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly ReversalService $service,
        private readonly PaymentRepository $payments,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['status', 'payment_id']);
        [$rows, $next] = $this->payments->reversalPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::reversal());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $reversal = $this->payments->reversal($this->context->tenantId(), $this->param($request, 'id'));
        if ($reversal === null || $reversal['establishment_id'] !== $this->context->establishmentId()) {
            throw HttpException::notFound('Annulation introuvable.');
        }

        return $this->responder->json(['data' => Resource::reversal()->toArray($reversal)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::REVERSAL_REQUEST);
        $data = (new ReversalRequest())->fromHttp($request);
        $reversal = $this->service->request($this->context->tenantId(), $this->context->establishmentId(), $principal->userId, (string) $data['payment_id'], (string) $data['reason']);

        return $this->responder->json(['data' => Resource::reversal()->toArray($reversal)], 202);
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::REVERSAL_APPROVE);
        $reversal = $this->service->approve($this->context->tenantId(), $this->context->establishmentId(), $principal->userId, $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::reversal()->toArray($reversal)]);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::REVERSAL_APPROVE);
        $reversal = $this->service->reject($this->context->tenantId(), $this->context->establishmentId(), $principal->userId, $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::reversal()->toArray($reversal)]);
    }
}
