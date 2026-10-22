<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\EntitlementService;
use Scholaris\Modules\Socle\Platform\Application\ServiceOptions;
use Scholaris\Modules\Socle\Platform\Domain\Regularisation;

/**
 * Tarification fine par le super admin : délais propres au client (essai, grâce, périodicité, niveau de blocage), catalogue
 * des options de service et souscription d'un client à une option. Actions sensibles : 2FA récente, motif, journal.
 */
final class PricingAdminController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly EntitlementService $entitlements,
        private readonly ServiceOptions $options,
        private readonly ClientLifecycle $clients,
        private readonly Catalog $catalog,
    ) {
    }

    /** PATCH /platform/clients/{tenant}/applications/{key}/terms { trial_days?, grace_days?, billing_period_months?, regularisation_level?, reason } : null remet la valeur du catalogue. */
    public function terms(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.pricing.manage', true);
        $tenantId = $this->tenantId($request);
        $input = Input::of($request, ['trial_days', 'grace_days', 'billing_period_months', 'regularisation_level', 'reason']);
        $changes = [];
        foreach (['trial_days' => [0, 365], 'grace_days' => [0, 90]] as $field => [$min, $max]) {
            if ($input->has($field)) {
                $changes[$field] = $input->raw($field) === null ? null : $input->integer($field, $min, $max);
            }
        }
        if ($input->has('billing_period_months')) {
            $value = $input->raw('billing_period_months');
            if ($value === null) {
                $changes['billing_period_months'] = null;
            } elseif (is_int($value) && in_array($value, EntitlementService::BILLING_PERIODS, true)) {
                $changes['billing_period_months'] = $value;
            } else {
                $input->fail('billing_period_months', 'Valeur attendue : 1 (mensuelle), 3 (trimestrielle) ou 12 (annuelle).');
            }
        }
        if ($input->has('regularisation_level')) {
            $changes['regularisation_level'] = $input->raw('regularisation_level') === null ? null : $input->choice('regularisation_level', Regularisation::LEVELS);
        }
        $reason = $input->longText('reason', 500, true);
        if ($changes === []) {
            $input->fail('trial_days', 'Aucune modification demandée.');
        }
        $input->validate();

        return $this->responder->json(['data' => $this->entitlements->setTerms($tenantId, PlatformGuard::param($request, 'key'), $changes, (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST /platform/clients/{tenant}/applications/{key}/options { option_key, price_xaf?, reason } : souscrit ou change le prix propre au client. */
    public function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.pricing.manage', true);
        $tenantId = $this->tenantId($request);
        $key = PlatformGuard::param($request, 'key');
        $input = Input::of($request, ['option_key', 'price_xaf', 'reason']);
        $optionKey = $input->text('option_key', 2, 30, true, '/^[a-z][a-z0-9_]+$/');
        $price = $input->has('price_xaf') && $input->raw('price_xaf') !== null ? $input->integer('price_xaf', 0, 100000000) : null;
        $reason = $input->longText('reason', 500, true);
        $input->validate();
        $this->entitlements->rowOrFail($tenantId, $key);
        $this->options->subscribe($tenantId, $key, (string) $optionKey, $price, (string) $reason, $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->entitlements->present($this->entitlements->rowOrFail($tenantId, $key))]);
    }

    /** POST /platform/clients/{tenant}/applications/{key}/options/{option}/remove { reason } */
    public function unsubscribe(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.pricing.manage', true);
        $tenantId = $this->tenantId($request);
        $key = PlatformGuard::param($request, 'key');
        $input = Input::of($request, ['reason']);
        $reason = $input->longText('reason', 500, true);
        $input->validate();
        $this->entitlements->rowOrFail($tenantId, $key);
        $this->options->unsubscribe($tenantId, $key, PlatformGuard::param($request, 'option'), (string) $reason, $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->entitlements->present($this->entitlements->rowOrFail($tenantId, $key))]);
    }

    /** GET /platform/service-options */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.catalog.manage');

        return $this->responder->json(['data' => $this->options->all()]);
    }

    /** POST /platform/service-options { option_key, label_fr, label_en, monthly_price_xaf, app_keys[] } */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.catalog.manage', true);
        $input = Input::of($request, ['option_key', 'label_fr', 'label_en', 'monthly_price_xaf', 'app_keys']);
        $key = $input->text('option_key', 2, 30, true, '/^[a-z][a-z0-9_]+$/');
        $labelFr = $input->text('label_fr', 2, 120);
        $labelEn = $input->text('label_en', 2, 120);
        $price = $input->integer('monthly_price_xaf', 0, 100000000);
        $apps = $this->appKeys($input);
        $input->validate();

        return $this->responder->json(['data' => $this->options->create([
            'option_key' => (string) $key, 'label_fr' => (string) $labelFr, 'label_en' => (string) $labelEn, 'monthly_price_xaf' => (int) $price, 'app_keys' => $apps,
        ], $principal->userId, PlatformGuard::ip($request))], 201);
    }

    /** PATCH /platform/service-options/{key} { label_fr?, label_en?, monthly_price_xaf?, app_keys?, active? } */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.catalog.manage', true);
        $key = PlatformGuard::param($request, 'key');
        $this->options->findOrFail($key);
        $input = Input::of($request, ['label_fr', 'label_en', 'monthly_price_xaf', 'app_keys', 'active']);
        $changes = [];
        foreach (['label_fr', 'label_en'] as $field) {
            if ($input->has($field)) {
                $changes[$field] = $input->text($field, 2, 120);
            }
        }
        if ($input->has('monthly_price_xaf')) {
            $changes['monthly_price_xaf'] = $input->integer('monthly_price_xaf', 0, 100000000);
        }
        if ($input->has('app_keys')) {
            $changes['app_keys'] = $this->appKeys($input);
        }
        if ($input->has('active')) {
            if (!is_bool($input->raw('active'))) {
                $input->fail('active', 'Booléen attendu.');
            } else {
                $changes['active'] = $input->raw('active');
            }
        }
        $input->validate();

        return $this->responder->json(['data' => $this->options->update($key, $changes, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** DELETE /platform/service-options/{key} : refusé si des clients y ont souscrit. */
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.catalog.manage', true);
        $this->options->delete(PlatformGuard::param($request, 'key'), $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => ['deleted' => true]]);
    }

    /** @return list<string> applications concernées ([] : toutes) */
    private function appKeys(Input $input): array
    {
        $raw = $input->raw('app_keys');
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 20) {
            $input->fail('app_keys', 'Liste de clés d\'applications attendue.');

            return [];
        }
        $keys = [];
        foreach ($raw as $key) {
            if (!is_string($key) || $this->catalog->find($key) === null) {
                $input->fail('app_keys', 'Application inconnue du catalogue.');

                return [];
            }
            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        return $this->clients->tenant(PlatformGuard::param($request, 'tenant'))['id'];
    }
}
