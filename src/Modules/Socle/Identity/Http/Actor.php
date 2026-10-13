<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Application\CurrentSession;
use Scholaris\Modules\Socle\Identity\Domain\AccessToken;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/** Personne connectée derrière la requête (compte utilisateur, jamais un compte de service) et sa session. */
final class Actor
{
    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly CurrentSession $session,
    ) {
    }

    public function user(ServerRequestInterface $request): User
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $this->users->find($principal->tenantId, $principal->userId) ?? throw HttpException::forbidden();
    }

    public function token(): AccessToken
    {
        return $this->session->token() ?? throw HttpException::forbidden('Cette action exige une session utilisateur.');
    }

    public function locale(ServerRequestInterface $request): string
    {
        return $request->getAttribute('locale') === 'en' ? 'en' : 'fr';
    }
}
