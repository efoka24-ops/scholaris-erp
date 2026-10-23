<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Identity\Application\AuthService;
use Scholaris\Modules\Socle\Identity\Application\ClientInfo;
use Scholaris\Modules\Socle\Identity\Application\TokenService;
use Scholaris\Modules\Socle\Identity\Domain\Exception\AccountLocked;
use Scholaris\Modules\Socle\Identity\Domain\Exception\InvalidCredentials;
use Scholaris\Modules\Socle\Identity\Domain\Exception\LockedOut;
use Scholaris\Modules\Socle\Identity\Http\Requests\AuthenticatorLoginRequest;
use Scholaris\Modules\Socle\Identity\Http\Requests\LoginRequest;
use Scholaris\Modules\Socle\Identity\Http\Resources\IdentityResource;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoPeopleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\TokenAuthenticator;

/** Connexion, déconnexion, appareils et sessions. */
final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PdoPeopleRepository $people,
        private readonly JsonResponder $responder,
        private readonly Problems $problems,
        private readonly Actor $actor,
        private readonly Config $config,
    ) {
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $input = LoginRequest::from($request);
        try {
            $result = $this->auth->login($input->identifier, $input->password, $input->remember, ClientInfo::fromRequest($request, $input->deviceId));
        } catch (InvalidCredentials $e) {
            $left = $e->attemptsLeft;

            return $this->problems->build(
                $request,
                401,
                'Identifiants incorrects',
                sprintf('Mot de passe incorrect. Il vous reste %d %s avant un verrouillage de 15 minutes.', $left, $left > 1 ? 'essais' : 'essai'),
                'invalid-credentials',
                ['attempts_left' => $left],
                ['WWW-Authenticate' => 'Bearer'],
            );
        } catch (LockedOut $e) {
            return $this->problems->build(
                $request,
                423,
                'Compte verrouillé',
                'Trop d\'échecs de connexion. Votre compte est verrouillé pendant 15 minutes.',
                'locked',
                ['attempts_left' => 0, 'locked_until' => $e->lockedUntil],
                ['Retry-After' => (string) max(1, $e->retryAfter)],
            );
        } catch (AccountLocked) {
            return $this->problems->build(
                $request,
                423,
                'Compte bloqué',
                'Votre compte est bloqué. Réinitialisez votre mot de passe pour le rouvrir.',
                'account-locked',
            );
        }

        return $this->loginResponse($result, $input->web);
    }

    /**
     * POST /auth/login-authenticator : connexion de l'administrateur de la plateforme par identifiant + code de l'application
     * d'authentification (sans mot de passe). Mêmes verrouillages et réponses que la connexion par mot de passe.
     */
    public function loginAuthenticator(ServerRequestInterface $request): ResponseInterface
    {
        $input = AuthenticatorLoginRequest::from($request);
        try {
            $result = $this->auth->loginWithAuthenticator($input->identifier, $input->code, $input->remember, ClientInfo::fromRequest($request, $input->deviceId));
        } catch (InvalidCredentials $e) {
            $left = $e->attemptsLeft;

            return $this->problems->build(
                $request,
                401,
                'Identifiants incorrects',
                sprintf('Identifiant ou code incorrect. Il vous reste %d %s avant un verrouillage de 15 minutes.', $left, $left > 1 ? 'essais' : 'essai'),
                'invalid-credentials',
                ['attempts_left' => $left],
                ['WWW-Authenticate' => 'Bearer'],
            );
        } catch (LockedOut $e) {
            return $this->problems->build(
                $request,
                423,
                'Compte verrouillé',
                'Trop d\'échecs de connexion. Votre compte est verrouillé pendant 15 minutes.',
                'locked',
                ['attempts_left' => 0, 'locked_until' => $e->lockedUntil],
                ['Retry-After' => (string) max(1, $e->retryAfter)],
            );
        } catch (AccountLocked) {
            return $this->problems->build($request, 423, 'Compte bloqué', 'Votre compte est bloqué. Réinitialisez votre mot de passe pour le rouvrir.', 'account-locked');
        }

        return $this->loginResponse($result, $input->web);
    }

    private function loginResponse(\Scholaris\Modules\Socle\Identity\Application\LoginResult $result, bool $web): ResponseInterface
    {
        $person = $this->people->find($result->user->tenantId, $result->user->personId);
        $payload = [
            'token_type' => 'Bearer',
            'expires_at' => $result->expiresAt,
            'new_device' => $result->newDevice,
            'two_factor_required' => $result->twoFactorRequired,
            'two_factor_setup_required' => $result->twoFactorSetupRequired,
            // Mot de passe provisoire : la session ne sert qu'à POST /auth/change-password tant qu'il n'est pas remplacé.
            'password_change_required' => $result->passwordChangeRequired,
            'user' => IdentityResource::user($result->user, $person),
        ];
        if (!$web) {
            $payload['token'] = $result->token;

            return $this->responder->json($payload);
        }

        // Web : le jeton ne transite que dans un cookie HttpOnly, hors de portée du JavaScript de la page.
        $lifetime = $result->remember ? TokenService::TTL_REMEMBER : TokenService::TTL_DEFAULT;

        return $this->responder->json($payload)->withHeader('Set-Cookie', $this->cookie($result->token, $lifetime));
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout($this->actor->token());

        return $this->problems->noContent()->withHeader('Set-Cookie', $this->cookie('', 0));
    }

    public function logoutEverywhere(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor->user($request);
        $this->auth->logoutEverywhere($user->tenantId, $user->id);

        return $this->problems->noContent()->withHeader('Set-Cookie', $this->cookie('', 0));
    }

    public function devices(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor->user($request);
        $devices = $this->auth->devices($user->tenantId, $user->id, $this->actor->token()->deviceId);

        return $this->responder->json(['data' => array_map(IdentityResource::device(...), $devices)]);
    }

    public function revokeDevice(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor->user($request);
        $this->auth->revokeDevice($user->tenantId, $user->id, (string) $request->getAttribute('id'));

        return $this->problems->noContent();
    }

    public function notMe(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor->user($request);
        $this->auth->notMe($user->tenantId, $user->id);

        return $this->responder->json(['status' => 'locked', 'detail' => 'Votre compte est bloqué et tous vos appareils sont déconnectés. Réinitialisez votre mot de passe pour le rouvrir.'])
            ->withHeader('Set-Cookie', $this->cookie('', 0));
    }

    private function cookie(string $value, int $maxAge): string
    {
        $secure = str_starts_with((string) $this->config->get('app.url', ''), 'https://') ? '; Secure' : '';

        return sprintf('%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Strict%s', TokenAuthenticator::COOKIE, $value, $maxAge, $secure);
    }
}
