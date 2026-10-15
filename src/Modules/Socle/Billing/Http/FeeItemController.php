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
use Scholaris\Modules\Socle\Billing\Application\FeeKinds;
use Scholaris\Modules\Socle\Billing\Http\Requests\FeeItemRequest;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;
use Scholaris\Modules\Socle\Billing\Infrastructure\CatalogRepository;

/** Catalogue de frais de l'établissement actif (inscription, pension, autres). */
final class FeeItemController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly CatalogRepository $catalog,
        private readonly BillingClock $clock,
        private readonly AuditLog $audit,
        private readonly FeeKinds $kinds,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['kind', 'active', 'code']);
        [$rows, $next] = $this->catalog->page(CatalogRepository::ITEMS, $this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        $response = $this->pageResponse($request, $rows, $next, Resource::feeItem());
        if (array_filter($rows, static fn (array $r): bool => ($r['code'] ?? null) !== null) === []) {
            return $response;
        }
        $body = (array) json_decode((string) $response->getBody(), true);
        $body['data'] = array_map(Resource::feeItemRow(...), $rows);

        return $this->responder->json($body);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);

        return $this->responder->json(['data' => Resource::feeItemRow($this->find($request))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::MANAGE);
        $data = (new FeeItemRequest($this->kinds->all()))->fromHttp($request);
        $this->assertCodeFree($data['code'] ?? null, null);

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->catalog->insert(CatalogRepository::ITEMS, [
            'id' => $id, 'tenant_id' => $this->context->tenantId(), 'establishment_id' => $this->context->establishmentId(),
            'kind' => $data['kind'], 'code' => $data['code'] ?? null, 'name' => $data['name'], 'amount_xaf' => $data['amount_xaf'], 'active' => $data['active'] ?? 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->catalog->find(CatalogRepository::ITEMS, $this->context->tenantId(), $this->context->establishmentId(), $id) ?? [];
        $this->audit->append($this->context->tenantId(), $principal->userId, 'fee_item.created', 'fee_item', $id, null, Resource::feeItemRow($row));

        return $this->responder->json(['data' => Resource::feeItemRow($row)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, BillingPolicy::MANAGE);
        $before = $this->find($request);
        $changes = (new FeeItemRequest($this->kinds->all()))->fromHttp($request, true);
        if ($changes === []) {
            throw HttpException::validation(['body' => ['Aucune modification fournie.']]);
        }

        if (isset($changes['code']) && $before['code'] !== null && $changes['code'] !== $before['code']) {
            throw HttpException::conflict('Le code d\'un frais est stable : il ne peut plus être modifié.');
        }
        $this->assertCodeFree($changes['code'] ?? null, (string) $before['id']);
        $tenantId = $this->context->tenantId();
        $this->catalog->update(CatalogRepository::ITEMS, $tenantId, $this->context->establishmentId(), (string) $before['id'], $changes + ['updated_at' => $this->clock->now()]);
        $after = $this->find($request);
        $this->audit->append($tenantId, $principal->userId, 'fee_item.updated', 'fee_item', (string) $before['id'], Resource::feeItemRow($before), Resource::feeItemRow($after));

        return $this->responder->json(['data' => Resource::feeItemRow($after)]);
    }

    private function assertCodeFree(mixed $code, ?string $selfId): void
    {
        if (!is_string($code)) {
            return;
        }
        $owner = $this->catalog->findByCode($this->context->tenantId(), $this->context->establishmentId(), $code);
        if ($owner !== null && $owner['id'] !== $selfId) {
            throw HttpException::conflict('Ce code de frais est déjà utilisé dans cet établissement.');
        }
    }

    /** @return array<string, mixed> */
    private function find(ServerRequestInterface $request): array
    {
        return $this->catalog->find(CatalogRepository::ITEMS, $this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'))
            ?? throw HttpException::notFound('Frais introuvable.');
    }
}
