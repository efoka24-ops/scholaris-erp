<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformStaff;
use Scholaris\Modules\Socle\Platform\Application\AdministratorAccounts;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;
use Scholaris\Modules\Socle\Platform\Domain\PlatformPermissions;

/**
 * Administrateurs des clients (/platform/clients/{tenant}/administrators/**) et de la plateforme
 * (/platform/administrators/**) : lister, inviter, renvoyer l'invitation, bloquer, débloquer, réinitialiser l'accès.
 * Écritures avec 2FA récente. Seuls les comptes super_admin d'un client sont visibles : jamais ses autres utilisateurs.
 */
final class AdministratorsAdminController
{
    private const NAME = '/^\p{L}[\p{L}\p{M} \'’.\-]*$/u';
    private const PLATFORM_NAME = 'Plateforme SCHOLARIS';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly AdministratorAccounts $accounts,
        private readonly ClientLifecycle $clients,
        private readonly PlatformStaff $staff,
    ) {
    }

    /** GET /platform/clients/{tenant}/administrators | GET /platform/administrators */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId] = $this->scope($request, false);

        return $this->responder->json(['data' => $this->accounts->list($tenantId)]);
    }

    /** POST …/administrators { first_name, last_name, email?, phone?, locale? } */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $tenantName, $principal] = $this->scope($request, true);
        $platform = $tenantId === PlatformAudit::PLATFORM_TENANT;
        $input = Input::of($request, $platform ? ['first_name', 'last_name', 'email', 'phone', 'locale', 'role'] : ['first_name', 'last_name', 'email', 'phone', 'locale']);
        $person = [
            'first_name' => (string) $input->text('first_name', 1, 80, true, self::NAME),
            'last_name' => (string) $input->text('last_name', 1, 80, true, self::NAME),
            'email' => $input->email('email', false),
            'phone' => $input->phone('phone', false),
            'locale' => $input->choice('locale', ['fr', 'en'], false) ?? 'fr',
        ];
        if ($person['email'] === null && $person['phone'] === null) {
            $input->fail('email', 'Indiquez un e-mail ou un téléphone : il servira d\'identifiant de connexion.');
        }
        $role = $platform ? ($input->choice('role', PlatformPermissions::ROLES, false) ?? AdministratorAccounts::PLATFORM_ROLE) : AdministratorAccounts::PLATFORM_ROLE;
        $input->validate();

        return $this->responder->json(['data' => $this->accounts->invite($tenantId, $tenantName, $person, $principal->userId, PlatformGuard::ip($request), $role)], 201);
    }

    /** PATCH /platform/administrators/{user}/role { role } : rôle de plateforme (administrateur, support, finance, audit). */
    public function role(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.admins.manage', true);
        $input = Input::of($request, ['role']);
        $role = (string) $input->choice('role', PlatformPermissions::ROLES);
        $input->validate();

        return $this->responder->json(['data' => $this->accounts->setRole(PlatformGuard::param($request, 'user'), $role, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** GET /platform/me : rôle et permissions `platform.*` du membre connecté (menus et écrans du front). */
    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.access');

        return $this->responder->json(['data' => [
            'user_id' => $principal->userId,
            'role' => $this->staff->roleOf($principal->userId),
            'permissions' => $this->guard->permissionsOf($principal),
        ]]);
    }

    /** POST …/administrators/{user}/resend-invitation */
    public function resend(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $tenantName, $principal] = $this->scope($request, true);
        Input::of($request, [])->validate();

        return $this->responder->json(['data' => $this->accounts->resendInvitation($tenantId, $tenantName, PlatformGuard::param($request, 'user'), $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST …/administrators/{user}/block (désactiver) */
    public function block(ServerRequestInterface $request): ResponseInterface
    {
        return $this->blocked($request, true);
    }

    /** POST …/administrators/{user}/unblock */
    public function unblock(ServerRequestInterface $request): ResponseInterface
    {
        return $this->blocked($request, false);
    }

    /** POST …/administrators/{user}/reset-access */
    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $tenantName, $principal] = $this->scope($request, true);
        Input::of($request, [])->validate();

        return $this->responder->json(['data' => $this->accounts->resetAccess($tenantId, $tenantName, PlatformGuard::param($request, 'user'), $principal->userId, PlatformGuard::ip($request))]);
    }

    private function blocked(ServerRequestInterface $request, bool $blocked): ResponseInterface
    {
        [$tenantId, , $principal] = $this->scope($request, true);
        Input::of($request, [])->validate();

        return $this->responder->json(['data' => $this->accounts->setBlocked($tenantId, PlatformGuard::param($request, 'user'), $blocked, $principal->userId, PlatformGuard::ip($request))]);
    }

    /**
     * Client concerné (attribut de route `tenant`) ou la plateforme elle-même (routes /platform/administrators).
     *
     * @return array{0: string, 1: string, 2: Principal}
     */
    private function scope(ServerRequestInterface $request, bool $write): array
    {
        $platform = $request->getAttribute('tenant') === null;
        $permissions = $platform
            ? ($write ? ['platform.admins.manage'] : ['platform.admins.read', 'platform.admins.manage'])
            : ($write ? ['platform.clients.manage'] : ['platform.users.read', 'platform.clients.manage']);
        $principal = $this->guard->platformAny($request, $permissions, $write);
        if ($platform) {
            return [PlatformAudit::PLATFORM_TENANT, self::PLATFORM_NAME, $principal];
        }
        $client = $this->clients->show(PlatformGuard::param($request, 'tenant'));
        if ($write) {
            $this->clients->assertNotArchived($client);
        }

        return [(string) $client['id'], (string) $client['name'], $principal];
    }
}
