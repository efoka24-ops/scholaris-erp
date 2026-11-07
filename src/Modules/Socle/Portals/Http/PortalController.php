<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Portals\Application\PortalService;

/** Portails : authentification et lien personne seulement (aucune permission, aucun établissement imposé). */
final class PortalController
{
    public function __construct(private readonly JsonResponder $responder, private readonly PortalService $portals)
    {
    }

    public function family(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);

        return $this->responder->json(['data' => $this->portals->family($principal->tenantId, $principal->userId)]);
    }

    public function learner(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);

        return $this->responder->json(['data' => $this->portals->learner($principal->tenantId, $principal->userId)]);
    }

    private function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
    }
}
