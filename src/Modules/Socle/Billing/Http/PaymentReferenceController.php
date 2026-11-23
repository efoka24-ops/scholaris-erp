<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Billing\Application\PaymentReferenceService;
use Scholaris\Modules\Socle\Billing\Http\Requests\PaymentReferenceRequest;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;

/**
 * Références de paiement famille et solde agrégé tous établissements. Un payeur en libre-service (portail famille ou
 * apprenant, D2) ne voit et n'émet que SA référence.
 */
final class PaymentReferenceController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly PaymentReferenceService $service,
        private readonly PaymentRepository $payments,
        private readonly SelfServiceScope $self,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $selfId = $this->staffOrSelf($request, $this->self, BillingPolicy::SELF_READ, BillingPolicy::READ, BillingPolicy::MANAGE, BillingPolicy::CASH);
        $query = ListQuery::fromRequest($request, ['guarantor_id', 'reference']);
        $filters = $query->filters;
        if ($selfId !== null) {
            if (isset($filters['guarantor_id']) && $filters['guarantor_id'] !== $selfId) {
                return $this->pageResponse($request, [], null, Resource::paymentReference());
            }
            $filters['guarantor_id'] = $selfId;
        }
        [$rows, $next] = $this->payments->referencePage($this->context->tenantId(), $filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::paymentReference());
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $selfId = $this->staffOrSelf($request, $this->self, BillingPolicy::SELF_PAY, BillingPolicy::MANAGE, BillingPolicy::CASH);
        $principal = $this->context->principal($request);
        $data = (new PaymentReferenceRequest())->fromHttp($request);
        if ($selfId !== null && (string) $data['guarantor_id'] !== $selfId) {
            throw HttpException::forbidden('Depuis le portail, vous ne pouvez obtenir que votre propre référence de paiement.');
        }
        $reference = $this->service->issueFor($this->context->tenantId(), $this->context->establishmentId(), $principal->userId, (string) $data['guarantor_id']);

        return $this->responder->json(['data' => Resource::paymentReference()->toArray($reference)], 201);
    }

    public function balance(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);

        return $this->responder->json(['data' => $this->service->balance($this->context->tenantId(), $this->param($request, 'reference'))]);
    }
}
