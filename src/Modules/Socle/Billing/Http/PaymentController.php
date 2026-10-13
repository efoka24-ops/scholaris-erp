<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Billing\Application\PaymentResult;
use Scholaris\Modules\Socle\Billing\Application\PaymentService;
use Scholaris\Modules\Socle\Billing\Application\RecordPayment;
use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;
use Scholaris\Modules\Socle\Billing\Http\Requests\PaymentRequest;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;

/** Journal des paiements, encaissement au guichet (POST /payments) et espèces en caisse (POST /cash/receipts). */
final class PaymentController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly PaymentService $service,
        private readonly PaymentRepository $payments,
        private readonly SelfServiceScope $self,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['channel', 'status', 'day', 'cash_session_id', 'payer_reference_id']);
        if (isset($query->filters['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query->filters['day']) !== 1) {
            throw HttpException::validation(['filter[day]' => ['Le jour doit être au format AAAA-MM-JJ.']]);
        }
        [$rows, $next] = $this->payments->paymentPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $this->present($rows), $next, Resource::payment());
    }

    /** Export CSV du journal des paiements (mêmes filtres que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $rows = $this->collectAll($request, ['channel', 'status', 'day', 'cash_session_id', 'payer_reference_id'], function (ListQuery $query): array {
            if (isset($query->filters['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query->filters['day']) !== 1) {
                throw HttpException::validation(['filter[day]' => ['Le jour doit être au format AAAA-MM-JJ.']]);
            }

            return $this->payments->paymentPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);
        });
        $resource = Resource::payment();

        return $this->csv(
            'paiements.csv',
            ['Identifiant', 'Canal', 'Montant (XAF)', 'Statut', 'Statut affiché', 'Reçu', 'Référence externe', 'Référence payeur', 'Apprenant', 'Facture', 'Session de caisse', 'Reçu par', 'Reçu le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['channel'], $r['journal_amount_xaf'], $r['status'], $r['journal_status'], $r['receipt_number'], $r['external_reference'], $r['payer_reference_id'], $r['learner_id'], $r['invoice_id'], $r['cash_session_id'], $r['received_by'], $r['received_at']];
            }, $this->present($rows)),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $selfId = $this->staffOrSelf($request, $this->self, BillingPolicy::SELF_READ, BillingPolicy::READ, BillingPolicy::MANAGE, BillingPolicy::CASH);
        $payment = $this->payments->payment($this->context->tenantId(), $this->param($request, 'id'));
        if ($payment === null || $payment['establishment_id'] !== $this->context->establishmentId()
            || ($selfId !== null && !$this->self->ownsPayment($this->context->tenantId(), $selfId, $payment))) {
            throw HttpException::notFound('Paiement introuvable.');
        }

        return $this->responder->json(['data' => $this->body(new PaymentResult($payment, $this->payments->receiptForPayment($this->context->tenantId(), (string) $payment['id'])))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $selfId = $this->staffOrSelf($request, $this->self, BillingPolicy::SELF_PAY, BillingPolicy::CASH, BillingPolicy::MANAGE);
        $principal = $this->context->principal($request);
        $data = (new PaymentRequest())->fromHttp($request);
        $channel = PaymentChannel::from((string) $data['channel']);
        if ($selfId !== null) {
            $this->checkSelfPayment($selfId, $channel, $data);
        }
        return $this->record($request, $principal->userId, $channel, $data, $selfId);
    }

    /**
     * Paiement en libre-service (D2) : mobile money seulement, jamais de référence d'opérateur fournie par le payeur
     * (elle confirmerait le paiement sans passer par l'opérateur), et une cible qui lui appartient (404 sinon).
     *
     * @param array<string, mixed> $data
     */
    private function checkSelfPayment(string $personId, PaymentChannel $channel, array $data): void
    {
        if (!$channel->isMobile()) {
            throw HttpException::forbidden('Depuis le portail, seul le paiement mobile money est possible.');
        }
        if (isset($data['external_reference'])) {
            throw HttpException::forbidden('La référence d\'opérateur est fournie par l\'opérateur, pas par le payeur.');
        }
        $tenantId = $this->context->tenantId();
        if (isset($data['family_reference']) && !$this->self->ownsReference($tenantId, $personId, (string) $data['family_reference'])) {
            throw HttpException::notFound('Référence de paiement inconnue.');
        }
        if (isset($data['learner_id']) && !in_array((string) $data['learner_id'], $this->self->learnerIds($tenantId, $personId), true)) {
            throw HttpException::notFound('Apprenant introuvable.');
        }
        if (isset($data['invoice_id']) && !$this->self->ownsInvoice($tenantId, $personId, (string) $data['invoice_id'])) {
            throw HttpException::notFound('Facture introuvable.');
        }
    }

    /** Encaissement d'espèces dans la caisse ouverte du caissier. */
    public function cashReceipt(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::CASH);
        $data = (new PaymentRequest(true))->fromHttp($request);

        return $this->record($request, $principal->userId, PaymentChannel::Cash, $data);
    }

    /** @param array<string, mixed> $data */
    private function record(ServerRequestInterface $request, string $userId, PaymentChannel $channel, array $data, ?string $selfId = null): ResponseInterface
    {
        $result = $this->service->record(new RecordPayment(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $userId,
            $channel,
            (int) $data['amount_xaf'],
            $this->context->idempotencyKey($request),
            isset($data['family_reference']) ? (string) $data['family_reference'] : null,
            isset($data['learner_id']) ? (string) $data['learner_id'] : null,
            isset($data['invoice_id']) ? (string) $data['invoice_id'] : null,
            isset($data['external_reference']) ? (string) $data['external_reference'] : null,
            isset($data['payer_phone']) ? (string) $data['payer_phone'] : null,
        ));

        // Un rejeu par clé d'un paiement qui n'est pas le sien ne révèle rien au payeur en libre-service.
        if ($selfId !== null && !$this->self->ownsPayment($this->context->tenantId(), $selfId, $result->payment)) {
            throw HttpException::notFound('Paiement introuvable.');
        }
        $status = $result->replayed ? 200 : ($result->payment['status'] === 'pending' ? 202 : 201);

        return $this->responder->json(['data' => $this->body($result)], $status);
    }

    /** @return array<string, mixed> */
    private function body(PaymentResult $result): array
    {
        $payment = $this->present([$result->payment])[0];
        $allocations = $this->payments->allocationsOf($this->context->tenantId(), (string) $result->payment['id']);

        return Resource::payment()->toArray($payment) + [
            'receipt' => $result->receipt === null ? null : Resource::receipt()->toArray($result->receipt),
            'allocations' => Resource::allocation()->collection($allocations),
        ];
    }

    /**
     * Ajoute le statut d'affichage du journal : Rapproché, En caisse, Sans référence, « Annulation · 2nd agent requis ».
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows): array
    {
        $tenantId = $this->context->tenantId();
        $ids = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        $numbers = $this->payments->receiptNumbers($tenantId, $ids);
        $pending = array_flip($this->payments->paymentsWithPendingReversal($tenantId, $ids));

        return array_map(function (array $row) use ($numbers, $pending): array {
            $id = (string) $row['id'];
            $row['receipt_number'] = $numbers[$id] ?? null;
            $row['journal_amount_xaf'] = isset($pending[$id]) ? -(int) $row['amount_xaf'] : (int) $row['amount_xaf'];
            $row['journal_status'] = match (true) {
                isset($pending[$id]) => 'Annulation · 2nd agent requis',
                $row['status'] === 'reversed' => 'Annulé',
                $row['status'] === 'pending' => 'En attente',
                $row['status'] === 'failed' => 'Échoué',
                PaymentChannel::from((string) $row['channel'])->isMobile() => $row['reconciled_at'] !== null ? 'Rapproché' : 'Sans référence',
                default => 'En caisse',
            };

            return $row;
        }, $rows);
    }
}
