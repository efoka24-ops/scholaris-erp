<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Domain\Permission;
use Scholaris\Modules\Socle\Identity\Domain\User;

/** POST /users : l'établissement inscrit une personne ; son compte reste en attente jusqu'à l'activation par code. */
final class UserController
{
    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly PermissionChecker $permissions,
        private readonly JsonResponder $responder,
    ) {
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        if (!$this->permissions->can($principal, Permission::IDENTITY_MANAGE, (string) $request->getAttribute('establishment_id'))) {
            throw HttpException::forbidden();
        }

        $body = Body::of($request);
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
}
