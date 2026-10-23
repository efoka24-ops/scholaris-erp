<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Application\DashboardService;
use Scholaris\Modules\Socle\Platform\Application\EntitlementService;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;
use Scholaris\Modules\Socle\Platform\Application\PlatformSettings;
use Scholaris\Modules\Socle\Platform\Application\SubscriptionPayments;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Scholaris\Modules\Socle\Platform\Domain\CameroonPhone;

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
        private readonly PlatformAudit $audit,
        private readonly PlatformSettings $settings,
    ) {
    }

    /** GET /platform/dashboard */
    public function dashboard(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.dashboard.read');

        return $this->responder->json(['data' => $this->dashboard->summary()]);
    }

    /** GET /platform/settings : réglages de la plateforme (valeur, défaut, bornes). */
    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.dashboard.read');

        return $this->responder->json(['data' => $this->settings->all()]);
    }

    /** PATCH /platform/settings : modifier un réglage (2FA récente, journalisé). */
    public function updateSettings(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.settings.manage', true);
        $input = Input::of($request, PlatformSettings::keys());
        $bounds = $this->settings->all();
        $changes = [];
        foreach (PlatformSettings::keys() as $key) {
            if ($input->has($key)) {
                $changes[$key] = $input->integer($key, $bounds[$key]['min'], $bounds[$key]['max']);
            }
        }
        if ($changes === []) {
            // Comme avant : un corps vide est refusé en désignant le premier réglage.
            $changes[PlatformSettings::ACCESS_CODE_TTL] = $input->integer(PlatformSettings::ACCESS_CODE_TTL, $bounds[PlatformSettings::ACCESS_CODE_TTL]['min'], $bounds[PlatformSettings::ACCESS_CODE_TTL]['max']);
        }
        $input->validate();

        $all = [];
        foreach ($changes as $key => $value) {
            $all = $this->settings->set($key, (int) $value, $principal->userId, PlatformGuard::ip($request));
        }

        return $this->responder->json(['data' => $all]);
    }

    /** GET /platform/settings/contact : contact et numéros de paiement de la plateforme (saisis, et valeurs effectives avec repli sur l'environnement). */
    public function contact(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.dashboard.read');

        return $this->responder->json(['data' => $this->settings->contactSettings()]);
    }

    /**
     * PATCH /platform/settings/contact { name?, email?, phone?, payments?: [{ channel: orange_money|mtn_momo, phone, holder }] }
     * Remplace le contact saisi (2FA récente, journalisé). Un champ vide se replie sur la variable d'environnement.
     */
    public function updateContact(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.settings.manage', true);
        $input = Input::of($request, ['name', 'email', 'phone', 'payments']);
        $name = $input->text('name', 2, 120, false);
        $email = $input->raw('email') === null || $input->raw('email') === '' ? null : $input->email('email');
        $rawPhone = $input->text('phone', 6, 25, false);
        $phone = $rawPhone === null ? null : CameroonPhone::normalize($rawPhone);
        if ($rawPhone !== null && $phone === null) {
            $input->fail('phone', 'Numéro mobile camerounais attendu (par exemple +237 6 99 00 00 01).');
        }
        $payments = $this->paymentNumbers($input);
        $input->validate();

        $this->settings->setContact(['name' => $name, 'email' => $email, 'phone' => $phone, 'payments' => $payments], $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->settings->contactSettings()]);
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

    /**
     * Numéros de paiement mobile money (au plus un par moyen, deux en tout) : numéro camerounais normalisé et nom du bénéficiaire.
     *
     * @return list<array{channel: string, phone: string, holder: string}>
     */
    private function paymentNumbers(Input $input): array
    {
        $raw = $input->raw('payments');
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > count(PlatformSettings::PAYMENT_CHANNELS)) {
            $input->fail('payments', 'Au plus un numéro par moyen : Orange Money et MTN MoMo.');

            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $i => $entry) {
            $field = 'payments.' . $i;
            $channel = is_array($entry) && is_string($entry['channel'] ?? null) ? $entry['channel'] : null;
            $phone = is_array($entry) && is_string($entry['phone'] ?? null) ? CameroonPhone::normalize($entry['phone']) : null;
            $holder = is_array($entry) && is_string($entry['holder'] ?? null) ? trim($entry['holder']) : '';
            if ($channel === null || !in_array($channel, PlatformSettings::PAYMENT_CHANNELS, true) || isset($seen[$channel])) {
                $input->fail($field . '.channel', 'Moyen attendu (une seule fois) : ' . implode(', ', PlatformSettings::PAYMENT_CHANNELS) . '.');
                continue;
            }
            $seen[$channel] = true;
            if ($phone === null) {
                $input->fail($field . '.phone', 'Numéro mobile camerounais attendu (par exemple +237 6 99 00 00 01).');
            }
            if ($holder === '' || mb_strlen($holder) > 120 || preg_match('/[\p{Cc}\p{Cf}<>]/u', $holder) === 1) {
                $input->fail($field . '.holder', 'Nom du bénéficiaire obligatoire (120 caractères au plus).');
            }
            if ($phone !== null && $holder !== '') {
                $out[] = ['channel' => $channel, 'phone' => $phone, 'holder' => $holder];
            }
        }

        return $out;
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
