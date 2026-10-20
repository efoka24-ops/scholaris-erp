<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Identity\Application\ClientInfo;
use Scholaris\Modules\Socle\Identity\Application\PasswordService;
use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Domain\Exception\InvalidCredentials;
use Scholaris\Modules\Socle\Identity\Domain\Exception\LockedOut;
use Scholaris\Modules\Socle\Identity\Http\Requests\ChangePasswordRequest;
use Scholaris\Modules\Socle\Identity\Http\Requests\ReconfirmRequest;

/** Mot de passe de la personne connectée : changement (forcé après un mot de passe provisoire) et confirmation récente. */
final class PasswordController
{
    public function __construct(
        private readonly PasswordService $passwords,
        private readonly PermissionResolver $resolver,
        private readonly JsonResponder $responder,
        private readonly Problems $problems,
        private readonly Actor $actor,
    ) {
    }

    public function change(ServerRequestInterface $request): ResponseInterface
    {
        $input = ChangePasswordRequest::from($request);
        try {
            $this->passwords->change($this->actor->user($request), $this->actor->token(), $input->current, $input->new, ClientInfo::fromRequest($request));
        } catch (InvalidCredentials $e) {
            return $this->wrong($request, $e);
        } catch (LockedOut $e) {
            return $this->locked($request, $e);
        }

        return $this->responder->json(['changed' => true, 'password_change_required' => false]);
    }

    public function reconfirm(ServerRequestInterface $request): ResponseInterface
    {
        $input = ReconfirmRequest::from($request);
        $user = $this->actor->user($request);
        try {
            $at = $this->passwords->reconfirm(
                $user,
                $this->actor->token(),
                $input->password,
                $this->resolver->isPlatformAdmin($user->tenantId, $user->personId),
                ClientInfo::fromRequest($request),
            );
        } catch (InvalidCredentials $e) {
            return $this->wrong($request, $e);
        } catch (LockedOut $e) {
            return $this->locked($request, $e);
        }

        return $this->responder->json(['confirmed' => true, 'confirmed_at' => $at, 'valid_for' => PasswordService::CONFIRMATION_TTL]);
    }

    /** 422 et non 401 : la session reste valable, seule la saisie est fausse. */
    private function wrong(ServerRequestInterface $request, InvalidCredentials $e): ResponseInterface
    {
        $left = $e->attemptsLeft;

        return $this->problems->build(
            $request,
            422,
            'Mot de passe incorrect',
            sprintf('Mot de passe incorrect. Il vous reste %d %s avant un verrouillage de 15 minutes.', $left, $left > 1 ? 'essais' : 'essai'),
            'invalid-password',
            ['attempts_left' => $left],
        );
    }

    private function locked(ServerRequestInterface $request, LockedOut $e): ResponseInterface
    {
        return $this->problems->build(
            $request,
            423,
            'Compte verrouillé',
            'Trop d\'échecs. Votre compte est verrouillé pendant 15 minutes.',
            'locked',
            ['attempts_left' => 0, 'locked_until' => $e->lockedUntil],
            ['Retry-After' => (string) max(1, $e->retryAfter)],
        );
    }
}
