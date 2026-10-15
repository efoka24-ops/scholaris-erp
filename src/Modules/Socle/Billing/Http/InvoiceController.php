<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Billing\Application\FeeKinds;
use Scholaris\Modules\Socle\Billing\Application\InvoiceService;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Http\Requests\DiscountRequest;
use Scholaris\Modules\Socle\Billing\Http\Requests\InvoiceRequest;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;

/** Factures de l'établissement actif, remises et bourses. */
final class InvoiceController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly InvoiceService $service,
        private readonly InvoiceRepository $invoices,
        private readonly FeeKinds $kinds,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['learner_id', 'status', 'enrollment_id', 'academic_year_id']);
        [$rows, $next] = $this->invoices->page($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::invoiceSummary());
    }

    /** Export CSV des factures (mêmes filtres que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $rows = $this->collectAll(
            $request,
            ['learner_id', 'status', 'enrollment_id', 'academic_year_id'],
            fn (ListQuery $query): array => $this->invoices->page($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query),
        );
        $resource = Resource::invoiceSummary();

        return $this->csv(
            'factures.csv',
            ['Identifiant', 'Inscription', 'Apprenant', 'Année scolaire', 'Classe', 'Statut', 'Brut (XAF)', 'Remises (XAF)', 'Net (XAF)', 'Payé (XAF)', 'Solde (XAF)', 'Créée le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['enrollment_id'], $r['learner_id'], $r['academic_year_id'], $r['structure_id'], $r['status'], $r['gross_xaf'], $r['discount_xaf'], $r['net_xaf'], $r['paid_xaf'], $r['balance_xaf'], $r['created_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);

        return $this->responder->json(['data' => Resource::invoiceDetail($this->detail($request))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::MANAGE);
        $data = (new InvoiceRequest($this->kinds->discountTargets()))->fromHttp($request);

        /** @var list<array{kind: string, mode: string, value: int, applies_to?: string, reason: string}> $discounts */
        $discounts = $data['discounts'] ?? [];
        $invoice = $this->service->generate(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $principal->userId,
            (string) $data['enrollment_id'],
            isset($data['level']) ? (string) $data['level'] : null,
            isset($data['track']) ? (string) $data['track'] : null,
            $discounts,
            ($data['payer'] ?? null) instanceof InvoicePayer ? $data['payer'] : null,
        );

        return $this->responder->json(['data' => Resource::invoiceDetail($invoice)], 201);
    }

    public function addDiscount(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::MANAGE);
        $data = (new DiscountRequest($this->kinds->discountTargets()))->fromHttp($request);
        $invoiceId = $this->param($request, 'id');

        $this->service->addDiscount(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $principal->userId,
            $invoiceId,
            (string) $data['kind'],
            (string) $data['mode'],
            (int) $data['value'],
            (string) ($data['applies_to'] ?? 'tuition'),
            (string) $data['reason'],
        );

        return $this->responder->json(['data' => Resource::invoiceDetail($this->service->detail($this->context->tenantId(), $invoiceId))], 201);
    }

    public function approveDiscount(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::MANAGE);
        $this->service->approveDiscount($this->context->tenantId(), $this->context->establishmentId(), $principal->userId, $this->param($request, 'id'));

        return $this->responder->json(['data' => ['status' => 'approved']]);
    }

    /** @return array<string, mixed> */
    private function detail(ServerRequestInterface $request): array
    {
        $invoice = $this->invoices->find($this->context->tenantId(), $this->param($request, 'id'));
        if ($invoice === null || $invoice['establishment_id'] !== $this->context->establishmentId()) {
            throw HttpException::notFound('Facture introuvable.');
        }

        return $this->service->detail($this->context->tenantId(), (string) $invoice['id']);
    }
}
