<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Application\DashboardService;
use Scholaris\Modules\Socle\Platform\Application\EntitlementService;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;
use Scholaris\Modules\Socle\Platform\Application\SubscriptionPayments;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Espace de l'administrateur de plateforme : tableau de bord, catalogue, clients et leurs droits d'usage, paiements.
 * Aucune donnée métier d'un client n'est exposée ici (ni élèves, ni notes, ni factures) : seulement l'identité du client,
 * ses établissements (nom, type) et ses abonnements.
 */
final class PlatformAdminController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly DashboardService $dashboard,
        private readonly Catalog $catalog,
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionPayments $payments,
        private readonly ClientProvisioning $clients,
        private readonly EstablishmentDirectory $establishments,
        private readonly PlatformAudit $audit,
    ) {
    }

    /** GET /platform/dashboard */
    public function dashboard(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.dashboard.read');

        return $this->responder->json(['data' => $this->dashboard->summary()]);
    }

    /** GET /platform/applications */
    public function catalog(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.catalog.manage');

        return $this->responder->json(['data' => $this->catalog->all()]);
    }

    /** PATCH /platform/applications/{key} */
    public function updateCatalog(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.catalog.manage', true);
        $key = PlatformGuard::param($request, 'key');
        $this->catalog->findOrFail($key);

        $input = Input::of($request, ['label', 'status', 'version', 'monthly_price_xaf', 'trial_days', 'grace_days']);
        $changes = array_filter([
            'label' => $input->has('label') ? $input->text('label', 2, 120) : null,
            'status' => $input->has('status') ? $input->choice('status', ApplicationManifest::STATUSES) : null,
            'version' => $input->has('version') ? $input->text('version', 1, 20, true, '/^[0-9A-Za-z.\-+]+$/') : null,
            'monthly_price_xaf' => $input->has('monthly_price_xaf') ? $input->integer('monthly_price_xaf', 0, 100000000) : null,
            'trial_days' => $input->has('trial_days') ? $input->integer('trial_days', 0, 365) : null,
            'grace_days' => $input->has('grace_days') ? $input->integer('grace_days', 0, 90) : null,
        ], static fn (mixed $value): bool => $value !== null);
        $input->validate();
        if ($changes !== [] && ($changes['monthly_price_xaf'] ?? null) !== null) {
            $this->guard->platformAdmin($request, 'platform.pricing.manage', true);
        }

        $app = $this->catalog->update($key, $changes);
        $this->audit->record(null, $principal->userId, 'platform.catalog_updated', 'platform_application', $key, $changes, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $app]);
    }

    /** GET /platform/clients */
    public function clients(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.clients.read');
        $items = [];
        foreach ($this->clients->tenants() as $tenant) {
            $items[] = $tenant + ['applications' => $this->entitlements->forTenant($tenant['id'])];
        }

        return $this->responder->json(['data' => $items]);
    }

    /** GET /platform/clients/{tenant} */
    public function client(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.clients.read');
        $tenant = $this->tenant($request);
        $establishments = [];
        foreach ($this->establishments->forTenant($tenant['id']) as $establishment) {
            $establishments[] = ['id' => $establishment->id, 'name' => $establishment->name, 'type' => $establishment->type];
        }

        return $this->responder->json(['data' => $tenant + [
            'establishments' => $establishments,
            'applications' => $this->entitlements->forTenant($tenant['id']),
        ]]);
    }

    /** POST /platform/clients/{tenant}/applications */
    public function openForClient(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.applications.manage', true);
        $tenant = $this->tenant($request);
        $input = Input::of($request, ['app_key']);
        $key = $input->text('app_key', 2, 20, true, '/^[a-z][a-z0-9_]+$/');
        $input->validate();

        $entitlement = $this->entitlements->open($tenant['id'], (string) $key, EntitlementService::SOURCE_PLATFORM, $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $entitlement], 201);
    }

    /** POST /platform/clients/{tenant}/applications/{key}/{suspend|close|reactivate} */
    public function suspend(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, 'suspend');
    }

    public function close(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, 'close');
    }

    public function reactivate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, 'reactivate');
    }

    /** PATCH /platform/clients/{tenant}/applications/{key} : tarif propre au client (null : tarif du catalogue). */
    public function setPrice(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.pricing.manage', true);
        $tenant = $this->tenant($request);
        $input = Input::of($request, ['monthly_price_xaf']);
        if (!$input->has('monthly_price_xaf')) {
            $input->fail('monthly_price_xaf', 'Champ obligatoire (null pour revenir au tarif du catalogue).');
        }
        $price = $input->raw('monthly_price_xaf') === null ? null : $input->integer('monthly_price_xaf', 0, 100000000);
        $input->validate();

        return $this->responder->json(['data' => $this->entitlements->setPrice($tenant['id'], PlatformGuard::param($request, 'key'), $price, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** GET /platform/payments */
    public function payments(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.payments.read');
        $query = ListQuery::fromRequest($request, ['status', 'tenant_id']);
        [$rows, $next] = $this->payments->page($query->filters['status'] ?? null, $query->filters['tenant_id'] ?? null, ListQuery::decodeCursor($query->cursor), $query->perPage);

        return $this->responder->json(['data' => $rows, 'meta' => ['next_cursor' => $next === null ? null : ListQuery::encodeCursor($next)]]);
    }

    private function decide(ServerRequestInterface $request, string $action): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.applications.manage', true);
        $tenant = $this->tenant($request);
        $input = Input::of($request, ['reason', 'until']);
        $reason = $input->longText('reason', 500, $action !== 'reactivate');
        $until = $action === 'reactivate' ? $input->date('until', false) : null;
        if ($action !== 'reactivate' && $input->has('until')) {
            $input->fail('until', 'Champ réservé à la réactivation.');
        }
        $input->validate();

        return $this->responder->json(['data' => $this->entitlements->decide(
            $tenant['id'],
            PlatformGuard::param($request, 'key'),
            $action,
            $reason,
            $until,
            $principal->userId,
            PlatformGuard::ip($request),
        )]);
    }

    /** @return array{id: string, name: string, status: string, created_at: string} */
    private function tenant(ServerRequestInterface $request): array
    {
        $id = PlatformGuard::param($request, 'tenant');
        foreach ($this->clients->tenants() as $tenant) {
            if ($tenant['id'] === $id) {
                return $tenant;
            }
        }

        throw HttpException::notFound('Client introuvable.');
    }
}
