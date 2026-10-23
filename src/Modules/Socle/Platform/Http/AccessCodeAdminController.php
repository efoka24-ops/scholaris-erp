<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;
use Scholaris\Modules\Socle\Platform\Application\UserAccessCodes;

/**
 * Dépannage et partage de l'accès : GET /platform/clients/{tenant}/users (lecture seule) ;
 * POST …/users/{user}/access-code { send?: none|email|sms } (nouveau code, l'ancien invalidé) ;
 * POST …/users/{user}/access-code/send { channel: email|sms, code } (renvoi du code ouvert) ;
 * mêmes routes sous /platform/administrators/{user}. Écritures avec 2FA récente ; réponses jamais mises en cache.
 */
final class AccessCodeAdminController
{
    private const PLATFORM_NAME = 'Plateforme SCHOLARIS';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly UserAccessCodes $codes,
        private readonly ClientLifecycle $clients,
    ) {
    }

    /** GET /platform/clients/{tenant}/users */
    public function users(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.clients.read');
        $client = $this->clients->show(PlatformGuard::param($request, 'tenant'));

        return $this->responder->json(['data' => $this->codes->users((string) $client['id'])]);
    }

    /** POST …/{user}/access-code */
    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $tenantName, $principal] = $this->scope($request);
        $input = Input::of($request, ['send']);
        $send = $input->choice('send', UserAccessCodes::SEND, false) ?? 'none';
        $input->validate();

        $issued = $this->codes->issue($tenantId, $tenantName, PlatformGuard::param($request, 'user'), $send, $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $issued], 201)->withHeader('Cache-Control', 'no-store');
    }

    /** POST …/{user}/access-code/send */
    public function send(ServerRequestInterface $request): ResponseInterface
    {
        [$tenantId, $tenantName, $principal] = $this->scope($request);
        $input = Input::of($request, ['channel', 'code']);
        $channel = (string) $input->choice('channel', ['email', 'sms']);
        $code = (string) $input->text('code', 6, 6, true, '/^\d{6}$/');
        $input->validate();

        $sent = $this->codes->send($tenantId, $tenantName, PlatformGuard::param($request, 'user'), $channel, $code, $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $sent])->withHeader('Cache-Control', 'no-store');
    }

    /** @return array{0: string, 1: string, 2: Principal} */
    private function scope(ServerRequestInterface $request): array
    {
        $platform = $request->getAttribute('tenant') === null;
        $principal = $this->guard->platformAdmin($request, $platform ? 'platform.admins.manage' : 'platform.clients.manage', true);
        if ($platform) {
            return [PlatformAudit::PLATFORM_TENANT, self::PLATFORM_NAME, $principal];
        }
        $client = $this->clients->show(PlatformGuard::param($request, 'tenant'));
        $this->clients->assertNotArchived($client);

        return [(string) $client['id'], (string) $client['name'], $principal];
    }
}
