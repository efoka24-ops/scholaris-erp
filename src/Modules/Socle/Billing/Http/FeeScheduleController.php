<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Application\BillingClock;
use Scholaris\Modules\Socle\Billing\Http\Requests\FeeScheduleRequest;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\CatalogRepository;

/** Grilles de frais par classe, niveau ou filière, avec leurs échéanciers en tranches. */
final class FeeScheduleController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly CatalogRepository $catalog,
        private readonly BillingClock $clock,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['academic_year_id', 'structure_id', 'level', 'track', 'fee_item_id']);
        [$rows, $next] = $this->catalog->page(CatalogRepository::SCHEDULES, $this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::feeSchedule());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $row = $this->catalog->find(CatalogRepository::SCHEDULES, $this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'))
            ?? throw HttpException::notFound('Grille introuvable.');

        return $this->responder->json(['data' => Resource::feeSchedule()->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::MANAGE);
        $data = (new FeeScheduleRequest())->fromHttp($request);
        $tenantId = $this->context->tenantId();
        $establishmentId = $this->context->establishmentId();

        if ($this->catalog->find(CatalogRepository::ITEMS, $tenantId, $establishmentId, (string) $data['fee_item_id']) === null) {
            throw HttpException::validation(['fee_item_id' => ['Ce frais n\'existe pas dans cet établissement.']]);
        }

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->catalog->insert(CatalogRepository::SCHEDULES, [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId,
            'academic_year_id' => $data['academic_year_id'], 'structure_id' => $data['structure_id'] ?? null,
            'level' => $data['level'] ?? null, 'track' => $data['track'] ?? null, 'fee_item_id' => $data['fee_item_id'],
            'amount_xaf' => $data['amount_xaf'], 'terms' => json_encode($data['terms'], JSON_THROW_ON_ERROR),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->catalog->find(CatalogRepository::SCHEDULES, $tenantId, $establishmentId, $id) ?? [];
        $this->audit->append($tenantId, $principal->userId, 'fee_schedule.created', 'fee_schedule', $id, null, Resource::feeSchedule()->toArray($row));

        return $this->responder->json(['data' => Resource::feeSchedule()->toArray($row)], 201);
    }
}
