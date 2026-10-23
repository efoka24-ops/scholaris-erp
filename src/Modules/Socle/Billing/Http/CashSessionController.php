<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Billing\Application\CashService;
use Scholaris\Modules\Socle\Billing\Http\Requests\CashRequests;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\CashRepository;

/** Caisse : ouverture avec fonds, arrêté avec écart nominatif, versements. */
final class CashSessionController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly CashService $service,
        private readonly CashRepository $cash,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['status', 'cashier_id']);
        [$rows, $next] = $this->cash->page($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::cashSession());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);

        return $this->responder->json(['data' => Resource::cashDetail($this->service->detail($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')))]);
    }

    public function open(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::CASH);
        $data = (new CashRequests(CashRequests::OPEN))->fromHttp($request);
        $session = $this->service->open(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $principal->userId,
            (int) $data['float_xaf'],
            isset($data['register_no']) ? (int) $data['register_no'] : null,
        );

        return $this->responder->json(['data' => Resource::cashDetail($session)], 201);
    }

    public function close(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::CASH, BillingPolicy::MANAGE);
        $data = (new CashRequests(CashRequests::CLOSE))->fromHttp($request);
        $session = $this->service->close(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $principal->userId,
            $this->policy->can($principal, $this->context->establishmentId(), BillingPolicy::MANAGE),
            $this->param($request, 'id'),
            (int) $data['counted_xaf'],
            isset($data['note']) ? (string) $data['note'] : null,
        );

        return $this->responder->json(['data' => Resource::cashDetail($session)]);
    }

    public function deposit(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::CASH, BillingPolicy::MANAGE);
        $data = (new CashRequests(CashRequests::DEPOSIT))->fromHttp($request);
        $session = $this->service->deposit(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $principal->userId,
            $this->param($request, 'id'),
            (int) $data['amount_xaf'],
            (string) $data['reference'],
        );

        return $this->responder->json(['data' => Resource::cashDetail($session)], 201);
    }
}
