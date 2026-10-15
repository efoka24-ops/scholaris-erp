<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Application\CurrentSession;
use Scholaris\Modules\Socle\Identity\Application\ServiceAccountService;
use Scholaris\Modules\Socle\Identity\Application\TokenService;

/**
 * Identifie l'appelant : jeton opaque en-tête `Authorization: Bearer` (mobile) ou cookie HttpOnly (web), ou clé de compte de service.
 * Un jeton dont la double authentification est exigée mais pas encore franchie n'ouvre que les routes marquées `pre_2fa`.
 */
final class TokenAuthenticator implements Authenticator
{
    public const COOKIE = 'scholaris_token';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly ServiceAccountService $serviceAccounts,
        private readonly PdoUserRepository $users,
        private readonly CurrentSession $session,
    ) {
    }

    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $this->session->set(null);

        $plain = null;
        $viaCookie = false;
        if (preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m) === 1) {
            $plain = $m[1];
        } elseif (isset($request->getCookieParams()[self::COOKIE]) && is_string($request->getCookieParams()[self::COOKIE])) {
            $plain = $request->getCookieParams()[self::COOKIE];
            $viaCookie = true;
        }
        if ($plain === null || $plain === '') {
            return null;
        }

        if (str_starts_with($plain, 'sk_')) {
            $account = $this->serviceAccounts->authenticate($plain);

            return $account === null ? null : new Principal('svc:' . $account['id'], $account['tenant_id']);
        }

        $token = $this->tokens->validate($plain);
        if ($token === null) {
            return null;
        }
        // Protection CSRF du cookie : une requête qui modifie des données doit porter un en-tête qu'un formulaire tiers ne peut pas poser.
        if ($viaCookie && !in_array($request->getMethod(), self::SAFE_METHODS, true) && $request->getHeaderLine('X-Requested-With') === '') {
            throw HttpException::forbidden('En-tête X-Requested-With manquant.');
        }

        $user = $this->users->find($token->tenantId, $token->userId);
        if ($user === null || !$user->isActive()) {
            return null;
        }
        $options = (array) $request->getAttribute('route_options', []);
        if ($token->isPendingTwoFactor() && ($options['pre_2fa'] ?? false) !== true) {
            throw new HttpException(
                403,
                'Double authentification requise',
                'Validez votre double authentification pour accéder à cette ressource.',
                'https://api.scholaris.cm/errors/two-factor-required',
            );
        }

        $this->tokens->touchIfStale($token);
        $this->session->set($token);

        return new Principal($user->id, $user->tenantId);
    }
}
