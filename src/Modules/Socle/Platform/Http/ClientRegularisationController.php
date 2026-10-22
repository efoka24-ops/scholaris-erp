<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;
use Scholaris\Modules\Socle\Platform\Application\RegularisationService;

/**
 * Écran de régularisation côté établissement : montant dû, détail, période, jours écoulés, reçus et contact. Tout
 * utilisateur du client le lit (il n'expose que son propre client) ; seuls les titulaires de `applications.manage` peuvent payer.
 */
final class ClientRegularisationController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly RegularisationService $regularisation,
        private readonly PermissionChecker $permissions,
        private readonly AccessibleEstablishments $accessible,
    ) {
    }

    /** GET /me/regularisation */
    public function mine(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->clientUser($request);
        $canPay = false;
        foreach ($this->accessible->idsFor($principal->tenantId, $principal->userId) as $establishmentId) {
            if ($this->permissions->can($principal, 'applications.manage', $establishmentId)) {
                $canPay = true;
                break;
            }
        }

        return $this->responder->json(['data' => $this->regularisation->forClient($principal->tenantId, $canPay)]);
    }
}
