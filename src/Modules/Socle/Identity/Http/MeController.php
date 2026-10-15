<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Identity\Application\ContextService;
use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Application\TwoFactorPolicy;
use Scholaris\Modules\Socle\Identity\Contracts\TodoProvider;
use Scholaris\Modules\Socle\Identity\Http\Requests\SwitchContextRequest;
use Scholaris\Modules\Socle\Identity\Http\Resources\IdentityResource;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoPeopleRepository;

/** Profil de la personne connectée, lanceur « Mes établissements », changement de contexte, tâches à faire. */
final class MeController
{
    public function __construct(
        private readonly ContextService $contexts,
        private readonly PermissionResolver $resolver,
        private readonly PdoPeopleRepository $people,
        private readonly TodoProvider $todo,
        private readonly JsonResponder $responder,
        private readonly Actor $actor,
        private readonly TwoFactorPolicy $twoFactorPolicy,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor->user($request);
        $token = $this->actor->token();
        $person = $this->people->find($user->tenantId, $user->personId);
        $data = IdentityResource::user($user, $person) + [
            'greeting' => $person['first_name'] ?? null,
            // Le front s'en sert pour n'afficher que les écrans de plateforme à ce compte ; le serveur refuse de toute façon.
            'platform_admin' => $this->resolver->isPlatformAdmin($user->tenantId, $user->personId),
            'two_factor' => [
                'enabled' => $user->hasTwoFactor(),
                'required' => $this->twoFactorPolicy->requiredFor($this->resolver->hasSensitiveRole($user->tenantId, $user->personId), $this->resolver->isPlatformAdmin($user->tenantId, $user->personId)),
                'verified' => !$token->isPendingTwoFactor(),
            ],
            'context' => ['establishment_id' => $token->activeEstablishmentId, 'role' => $token->activeRole],
        ];

        return $this->responder->json(['data' => $data]);
    }

    public function contexts(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor->user($request);

        return $this->responder->json(['data' => $this->contexts->contexts($user, $this->actor->locale($request), true)]);
    }

    public function switchContext(ServerRequestInterface $request): ResponseInterface
    {
        $input = SwitchContextRequest::from($request);
        $result = $this->contexts->switch($this->actor->user($request), $this->actor->token(), $input->contextId, $input->role, $this->actor->locale($request));

        return $this->responder->json(['data' => $result]);
    }

    public function todo(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor->user($request);
        $items = $this->todo->forUser($user->tenantId, $user->id);

        return $this->responder->json(['data' => $items, 'meta' => ['count' => count($items)]]);
    }
}
