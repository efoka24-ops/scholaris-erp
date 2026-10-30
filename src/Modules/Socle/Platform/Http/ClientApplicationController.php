<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Application\EntitlementService;
use Scholaris\Modules\Socle\Platform\Application\SubscriptionPayments;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Côté client : lanceur d'applications de l'utilisateur, ouverture en libre-service (essai gratuit), paiement de
 * l'abonnement et reçus. Ouvrir et payer exigent `applications.manage` sur l'établissement actif.
 */
final class ClientApplicationController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly TenantContext $context,
        private readonly Catalog $catalog,
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionPayments $payments,
        private readonly AccessibleEstablishments $accessible,
        private readonly EstablishmentDirectory $establishments,
    ) {
    }

    /** GET /me/applications : applications ouvertes aux établissements de l'utilisateur, avec état et jours d'essai restants. */
    public function mine(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->clientUser($request);
        $byType = [];
        $ids = $this->accessible->idsFor($principal->tenantId, $principal->userId);
        foreach ($ids === [] ? [] : $this->establishments->findMany($principal->tenantId, $ids) as $establishment) {
            $byType[$establishment->type][] = ['id' => $establishment->id, 'name' => $establishment->name, 'type' => $establishment->type, 'accent' => $establishment->accent];
        }

        $opened = [];
        $openKeys = [];
        foreach ($this->entitlements->forTenant($principal->tenantId) as $entitlement) {
            $openKeys[] = $entitlement['app_key'];
            if ($entitlement['state'] === ApplicationAccess::CLOSED || !isset($byType[(string) $entitlement['target']])) {
                continue;
            }
            $opened[] = $entitlement + ['establishments' => $byType[(string) $entitlement['target']]];
        }

        $others = [];
        foreach ($this->catalog->listed() as $app) {
            if (!in_array($app['key'], $openKeys, true)) {
                $others[] = ['key' => $app['key'], 'label' => $app['label'], 'target' => $app['target'], 'family' => $app['family'], 'sector' => $app['sector'], 'status' => $app['status'], 'monthly_price_xaf' => $app['monthly_price_xaf'], 'trial_days' => $app['trial_days']];
            }
        }

        return $this->responder->json(['data' => $opened, 'meta' => ['catalog' => $others]]);
    }

    /** POST /applications/{key}/open : libre-service, essai gratuit de l'application pour tout le client. */
    public function open(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->clientManager($request, $this->context->establishmentId());
        $entitlement = $this->entitlements->open($this->context->tenantId(), PlatformGuard::param($request, 'key'), EntitlementService::SOURCE_SELF_SERVICE, $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $entitlement], 201);
    }

    /** POST /applications/{key}/payments { months, channel, payer_phone } ; en-tête Idempotency-Key facultatif. */
    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->clientManager($request, $this->context->establishmentId());
        $input = Input::of($request, ['months', 'channel', 'payer_phone']);
        $months = $input->integer('months', 1, SubscriptionPayments::MAX_MONTHS);
        $channel = $input->choice('channel', SubscriptionPayments::CHANNELS);
        $phone = $input->phone('payer_phone');
        $input->validate();
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key !== '' && preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $key) !== 1) {
            throw HttpException::badRequest('En-tête Idempotency-Key invalide (8 à 80 caractères alphanumériques).');
        }

        [$payment, $created] = $this->payments->initiate(
            $this->context->tenantId(),
            PlatformGuard::param($request, 'key'),
            (int) $months,
            (string) $channel,
            (string) $phone,
            $principal->userId,
            $key === '' ? null : $key,
        );

        return $this->responder->json(['data' => $payment], $created ? 202 : 200);
    }

    /** GET /applications/{key}/payments */
    public function payments(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->clientManager($request, $this->context->establishmentId());
        $tenantId = $this->context->tenantId();
        $key = PlatformGuard::param($request, 'key');

        return $this->responder->json(['data' => $this->payments->forApplication($tenantId, $key), 'meta' => ['entitlement' => $this->entitlements->present($this->entitlements->rowOrFail($tenantId, $key))]]);
    }

    /** GET /application-payments/{id}/receipt */
    public function receipt(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->clientManager($request, $this->context->establishmentId());

        return $this->responder->json(['data' => $this->payments->receipt($this->context->tenantId(), PlatformGuard::param($request, 'id'))]);
    }
}
