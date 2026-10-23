<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;
use Scholaris\Modules\Socle\Platform\Application\PlatformSettings;
use Scholaris\Modules\Socle\Platform\Application\RegularisationRequests;
use Scholaris\Modules\Socle\Platform\Application\RegularisationService;
use Scholaris\Modules\Socle\Platform\Domain\CameroonPhone;

/**
 * Écran de régularisation côté établissement : montant dû, détail, période, jours écoulés, reçus, contact, numéros de paiement
 * de la plateforme et suivi des déclarations de paiement. Tout utilisateur du client le lit (il n'expose que son propre client) ;
 * seuls les titulaires de `applications.manage` (le responsable) peuvent payer ou déclarer un paiement.
 */
final class ClientRegularisationController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly RegularisationService $regularisation,
        private readonly RegularisationRequests $requests,
        private readonly PermissionChecker $permissions,
        private readonly AccessibleEstablishments $accessible,
    ) {
    }

    /** GET /me/regularisation */
    public function mine(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->clientUser($request);
        $view = $this->regularisation->forClient($principal->tenantId, $this->canManage($principal));
        $view['requests'] = $this->requests->forClient($principal->tenantId);

        return $this->responder->json(['data' => $view]);
    }

    /**
     * POST /me/regularisation/declarations { app_key, kind: total|partial, amount_xaf?, channel, payer_phone, reference, idempotency_key? }
     * Déclare un paiement mobile money fait à la plateforme. Ne débloque rien : la plateforme vérifie le paiement.
     */
    public function declare(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->clientUser($request);
        if (!$this->canManage($principal)) {
            throw HttpException::forbidden('Seul le responsable de l\'établissement peut déclarer un paiement.');
        }
        $input = Input::of($request, ['app_key', 'kind', 'amount_xaf', 'channel', 'payer_phone', 'reference', 'idempotency_key']);
        $appKey = $input->text('app_key', 2, 20, true, '/^[a-z][a-z0-9_]+$/');
        $kind = $input->choice('kind', RegularisationRequests::KINDS);
        $channel = $input->choice('channel', PlatformSettings::PAYMENT_CHANNELS);
        $amount = $kind === RegularisationRequests::PARTIAL ? $input->integer('amount_xaf', 1, 100000000) : null;
        if ($kind === RegularisationRequests::TOTAL && $input->raw('amount_xaf') !== null) {
            $input->fail('amount_xaf', 'Le montant est le solde dû : ne le renseignez que pour un paiement partiel.');
        }
        $rawPhone = $input->text('payer_phone', 6, 25);
        $phone = $rawPhone === null ? null : CameroonPhone::normalize($rawPhone);
        if ($rawPhone !== null && $phone === null) {
            $input->fail('payer_phone', 'Numéro mobile camerounais attendu (par exemple +237 6 99 00 00 01).');
        }
        $reference = $input->text('reference', 3, 120);
        $key = $input->text('idempotency_key', 8, 80, false, '/^[A-Za-z0-9_\-]+$/');
        $input->validate();

        [$declaration, $created] = $this->requests->declare(
            $principal->tenantId,
            (string) $appKey,
            (string) $kind,
            $amount,
            (string) $channel,
            (string) $phone,
            (string) $reference,
            $key,
            $principal->userId,
            PlatformGuard::ip($request),
        );

        return $this->responder->json(['data' => $declaration], $created ? 201 : 200);
    }

    private function canManage(Principal $principal): bool
    {
        foreach ($this->accessible->idsFor($principal->tenantId, $principal->userId) as $establishmentId) {
            if ($this->permissions->can($principal, 'applications.manage', $establishmentId)) {
                return true;
            }
        }

        return false;
    }
}
