<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Établissements d'un client (nom, type, fermeture) : lister, créer (nursery, primary, secondary, higher), renommer,
 * fermer et rouvrir. Fermé, un établissement n'est plus proposé aux utilisateurs ; ses données restent.
 */
final class EstablishmentsAdminController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly ClientLifecycle $clients,
        private readonly ClientProvisioning $provisioning,
        private readonly PlatformAudit $audit,
    ) {
    }

    /** GET /platform/clients/{tenant}/establishments */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.clients.read');
        $tenantId = PlatformGuard::param($request, 'tenant');
        $this->clients->tenant($tenantId);

        return $this->responder->json(['data' => $this->provisioning->establishmentsOf($tenantId)]);
    }

    /** POST /platform/clients/{tenant}/establishments { name, type } */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $tenantId] = $this->writable($request);
        $input = Input::of($request, ['name', 'type']);
        $name = $input->text('name', 2, 160);
        $type = $input->choice('type', ApplicationManifest::TARGETS);
        $input->validate();
        $id = $this->provisioning->createEstablishment($tenantId, (string) $name, (string) $type);
        $this->audit->record($tenantId, $principal->userId, 'platform.establishment_created', 'establishment', $id, ['name' => $name, 'type' => $type], PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->find($tenantId, $id)], 201);
    }

    /** PATCH /platform/clients/{tenant}/establishments/{establishment} { name } */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $tenantId] = $this->writable($request);
        $id = PlatformGuard::param($request, 'establishment');
        $this->find($tenantId, $id);
        $input = Input::of($request, ['name']);
        $name = $input->text('name', 2, 160);
        $input->validate();
        $this->provisioning->renameEstablishment($tenantId, $id, (string) $name);
        $this->audit->record($tenantId, $principal->userId, 'platform.establishment_renamed', 'establishment', $id, ['name' => $name], PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->find($tenantId, $id)]);
    }

    /** POST /platform/clients/{tenant}/establishments/{establishment}/close { reason } */
    public function close(ServerRequestInterface $request): ResponseInterface
    {
        return $this->toggle($request, true);
    }

    /** POST /platform/clients/{tenant}/establishments/{establishment}/reopen */
    public function reopen(ServerRequestInterface $request): ResponseInterface
    {
        return $this->toggle($request, false);
    }

    private function toggle(ServerRequestInterface $request, bool $close): ResponseInterface
    {
        [$principal, $tenantId] = $this->writable($request);
        $id = PlatformGuard::param($request, 'establishment');
        $establishment = $this->find($tenantId, $id);
        $input = Input::of($request, ['reason']);
        $reason = $input->longText('reason', 500, $close);
        $input->validate();
        if (($establishment['closed_at'] !== null) === $close) {
            throw HttpException::conflict($close ? 'Cet établissement est déjà fermé.' : 'Cet établissement est ouvert.');
        }
        $this->provisioning->setEstablishmentClosed($tenantId, $id, $close);
        $this->audit->record($tenantId, $principal->userId, $close ? 'platform.establishment_closed' : 'platform.establishment_reopened', 'establishment', $id, ['reason' => $reason], PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->find($tenantId, $id)]);
    }

    /** @return array{0: Principal, 1: string} */
    private function writable(ServerRequestInterface $request): array
    {
        $principal = $this->guard->platformAdmin($request, 'platform.clients.manage', true);
        $tenantId = PlatformGuard::param($request, 'tenant');
        $this->clients->assertNotArchived($this->clients->show($tenantId));

        return [$principal, $tenantId];
    }

    /** @return array{id: string, name: string, type: string, closed_at: ?string, created_at: string} */
    private function find(string $tenantId, string $id): array
    {
        foreach ($this->provisioning->establishmentsOf($tenantId) as $establishment) {
            if ($establishment['id'] === $id) {
                return $establishment;
            }
        }

        throw HttpException::notFound('Établissement introuvable.');
    }
}
