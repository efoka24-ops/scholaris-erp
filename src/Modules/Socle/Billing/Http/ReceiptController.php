<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Billing\Application\ReceiptService;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;

/** Reçus numérotés (jamais supprimés) et leur PDF. */
final class ReceiptController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly PaymentRepository $payments,
        private readonly ReceiptService $receipts,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly SelfServiceScope $self,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $selfId = $this->staffOrSelf($request, $this->self, BillingPolicy::SELF_READ, BillingPolicy::READ, BillingPolicy::MANAGE, BillingPolicy::CASH);
        $query = ListQuery::fromRequest($request, ['payment_id', 'status', 'year']);
        $tenantId = $this->context->tenantId();
        [$rows, $next] = $selfId === null
            ? $this->payments->receiptPage($tenantId, $this->context->establishmentId(), $query->filters, $query)
            : $this->payments->receiptPageOwnedBy($tenantId, $this->context->establishmentId(), $this->self->referenceIds($tenantId, $selfId), $this->self->learnerIds($tenantId, $selfId), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::receipt());
    }

    /** Export CSV des reçus (mêmes filtres que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $rows = $this->collectAll(
            $request,
            ['payment_id', 'status', 'year'],
            fn (ListQuery $query): array => $this->payments->receiptPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query),
        );
        $resource = Resource::receipt();

        return $this->csv(
            'recus.csv',
            ['Identifiant', 'Paiement', 'Année', 'Numéro', 'Montant (XAF)', 'Statut', 'Émis le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['payment_id'], $r['year'], $r['number'], $r['amount_xaf'], $r['status'], $r['issued_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $selfId = $this->staffOrSelf($request, $this->self, BillingPolicy::SELF_READ, BillingPolicy::READ, BillingPolicy::MANAGE, BillingPolicy::CASH);

        return $this->responder->json(['data' => Resource::receipt()->toArray($this->visibleReceipt($request, $selfId))]);
    }

    public function pdf(ServerRequestInterface $request): ResponseInterface
    {
        $selfId = $this->staffOrSelf($request, $this->self, BillingPolicy::SELF_READ, BillingPolicy::READ, BillingPolicy::MANAGE, BillingPolicy::CASH);
        if ($selfId !== null) {
            $this->visibleReceipt($request, $selfId);
        }
        $binary = $this->receipts->pdf($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="recu.pdf"')
            ->withBody($this->streams->createStream($binary));
    }

    /**
     * Reçu de l'établissement actif ; pour un payeur en libre-service, seulement s'il porte sur un de SES paiements (404 sinon).
     *
     * @return array<string, mixed>
     */
    private function visibleReceipt(ServerRequestInterface $request, ?string $selfId): array
    {
        $tenantId = $this->context->tenantId();
        $receipt = $this->payments->receipt($tenantId, $this->param($request, 'id'));
        if ($receipt === null || $receipt['establishment_id'] !== $this->context->establishmentId()) {
            throw HttpException::notFound('Reçu introuvable.');
        }
        if ($selfId !== null) {
            $payment = $this->payments->payment($tenantId, (string) $receipt['payment_id']);
            if ($payment === null || !$this->self->ownsPayment($tenantId, $selfId, $payment)) {
                throw HttpException::notFound('Reçu introuvable.');
            }
        }

        return $receipt;
    }
}
