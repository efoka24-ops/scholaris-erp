<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Domain\Permission;
use Scholaris\Modules\Socle\Identity\Domain\User;

/**
 * POST /users : l'établissement inscrit une personne. Par défaut son compte reste en attente jusqu'à l'activation par code ;
 * avec `provisional_password: true` (connexion simple), le compte est utilisable tout de suite et le mot de passe provisoire
 * généré est rendu UNE fois (Cache-Control: no-store). POST /users/{id}/provisional-password en ré-émet un.
 */
final class UserController
{
    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly AccountAdministration $accounts,
        private readonly PermissionChecker $permissions,
        private readonly JsonResponder $responder,
    ) {
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->manager($request);

        $body = Body::of($request);
        if ($body->bool('provisional_password')) {
            $result = $this->accounts->createWithProvisionalPassword(
                $principal->tenantId,
                $body->string('first_name') ?? '',
                $body->string('last_name') ?? '',
                $body->string('phone'),
                $body->string('email'),
                $body->string('locale') ?? 'fr',
                $principal->userId,
                $body->string('person_id'),
            );

            return $this->secret($this->responder->json(['data' => $result], 201));
        }
        $result = $this->provisioner->provision(
            $principal->tenantId,
            $body->string('first_name') ?? '',
            $body->string('last_name') ?? '',
            $body->string('phone'),
            $body->string('email'),
            $body->string('locale') ?? 'fr',
            $body->string('person_id'),
        );

        return $this->responder->json(['data' => $result + ['status' => User::PENDING]], 201);
    }

    /** POST /users/{id}/provisional-password : ré-émission (mot de passe perdu). Option `reset_two_factor`. */
    public function provisionalPassword(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->manager($request);
        $body = Body::of($request);
        $result = $this->accounts->reissueProvisionalPassword(
            $principal->tenantId,
            (string) $request->getAttribute('id'),
            $principal->userId,
            (string) $request->getAttribute('establishment_id'),
            $body->bool('reset_two_factor'),
        );

        return $this->secret($this->responder->json(['data' => $result]));
    }

    private function manager(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        if (!$this->permissions->can($principal, Permission::IDENTITY_MANAGE, (string) $request->getAttribute('establishment_id'))) {
            throw HttpException::forbidden();
        }

        return $principal;
    }

    /** Réponse qui porte un secret : jamais mise en cache par le navigateur ni un relais. */
    private function secret(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
