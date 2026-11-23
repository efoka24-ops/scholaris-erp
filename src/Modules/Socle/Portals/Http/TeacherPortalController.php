<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Portals\Application\TeacherPortalService;

/** Portail enseignant : authentification et lien personne seulement, comme les autres portails. */
final class TeacherPortalController
{
    public function __construct(private readonly JsonResponder $responder, private readonly TeacherPortalService $portal)
    {
    }

    public function teacher(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $this->responder->json(['data' => $this->portal->teacher($principal->tenantId, $principal->userId)]);
    }
}
