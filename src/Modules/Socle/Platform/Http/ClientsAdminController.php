<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\EntitlementService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Clients vus par l'administrateur de plateforme : liste, fiche, création directe, modification, suspension,
 * réactivation, archivage. Lecture : platform.clients.read ; écriture : platform.clients.manage avec 2FA récente.
 * Aucune donnée métier du client n'est exposée (seulement sa fiche, ses établissements et ses abonnements).
 */
final class ClientsAdminController
{
    private const NAME = '/^\p{L}[\p{L}\p{M} \'’.\-]*$/u';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly ClientLifecycle $clients,
        private readonly EntitlementService $entitlements,
        private readonly ClientProvisioning $provisioning,
    ) {
    }

    /** GET /platform/clients?filter[state]=active|suspended|archived (par défaut : tous sauf archivés) */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.clients.read');
        $filter = ListQuery::fromRequest($request, ['state'])->filters['state'] ?? null;
        if ($filter !== null && !in_array($filter, ClientLifecycle::STATES, true)) {
            throw HttpException::validation(['filter[state]' => ['Valeur attendue : ' . implode(', ', ClientLifecycle::STATES) . '.']]);
        }

        return $this->responder->json(['data' => $this->clients->list($filter)]);
    }

    /** GET /platform/clients/{tenant} */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.clients.read');
        $tenantId = PlatformGuard::param($request, 'tenant');
        $client = $this->clients->show($tenantId);
        $establishments = [];
        foreach ($this->provisioning->establishmentsOf($tenantId) as $establishment) {
            $establishments[] = $establishment;
        }

        return $this->responder->json(['data' => $client + [
            'establishments' => $establishments,
            'applications' => $this->entitlements->forTenant($tenantId),
        ]]);
    }

    /** POST /platform/clients */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.clients.manage', true);
        $input = Input::of($request, ['name', 'contact_name', 'contact_email', 'contact_phone', 'city', 'region', 'locale', 'establishment_type']);
        $data = [
            'name' => (string) $input->text('name', 3, 160),
            'contact_name' => (string) $input->text('contact_name', 2, 160, true, self::NAME),
            'contact_email' => $input->email('contact_email', false),
            'contact_phone' => $input->phone('contact_phone', false),
            'city' => $input->text('city', 2, 80, false),
            'region' => $input->text('region', 2, 80, false),
            'locale' => $input->choice('locale', ['fr', 'en'], false) ?? 'fr',
            'establishment_type' => $input->choice('establishment_type', ApplicationManifest::TARGETS, false),
        ];
        if ($data['contact_email'] === null && $data['contact_phone'] === null) {
            $input->fail('contact_email', 'Indiquez un e-mail ou un téléphone de contact.');
        }
        $input->validate();

        return $this->responder->json(['data' => $this->clients->create($data, $principal->userId, PlatformGuard::ip($request))], 201);
    }

    /** PATCH /platform/clients/{tenant} */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.clients.manage', true);
        $tenantId = PlatformGuard::param($request, 'tenant');
        $this->clients->tenant($tenantId);
        $input = Input::of($request, ['name', 'contact_name', 'contact_email', 'contact_phone', 'city', 'region', 'locale']);
        $changes = [];
        $rules = [
            'name' => static fn (): ?string => $input->text('name', 3, 160),
            'contact_name' => static fn (): ?string => $input->text('contact_name', 2, 160, true, self::NAME),
            'contact_email' => static fn (): ?string => $input->email('contact_email', false),
            'contact_phone' => static fn (): ?string => $input->phone('contact_phone', false),
            'city' => static fn (): ?string => $input->text('city', 2, 80, false),
            'region' => static fn (): ?string => $input->text('region', 2, 80, false),
            'locale' => static fn (): ?string => $input->choice('locale', ['fr', 'en']),
        ];
        foreach ($rules as $field => $rule) {
            if ($input->has($field)) {
                $changes[$field] = $rule();
            }
        }
        if ($changes === []) {
            $input->fail('name', 'Aucune modification.');
        }
        $input->validate();

        return $this->responder->json(['data' => $this->clients->update($tenantId, $changes, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST /platform/clients/{tenant}/suspend { reason } */
    public function suspend(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $tenantId, $reason] = $this->decision($request, true);

        return $this->responder->json(['data' => $this->clients->suspend($tenantId, (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST /platform/clients/{tenant}/reactivate { reason? } */
    public function reactivate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $tenantId, $reason] = $this->decision($request, false);

        return $this->responder->json(['data' => $this->clients->reactivate($tenantId, $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST /platform/clients/{tenant}/archive { reason } */
    public function archive(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $tenantId, $reason] = $this->decision($request, true);

        return $this->responder->json(['data' => $this->clients->archive($tenantId, (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** @return array{0: Principal, 1: string, 2: ?string} */
    private function decision(ServerRequestInterface $request, bool $reasonRequired): array
    {
        $principal = $this->guard->platformAdmin($request, 'platform.clients.manage', true);
        $tenantId = PlatformGuard::param($request, 'tenant');
        $this->clients->tenant($tenantId);
        $input = Input::of($request, ['reason']);
        $reason = $input->longText('reason', 500, $reasonRequired);
        $input->validate();

        return [$principal, $tenantId, $reason];
    }
}
