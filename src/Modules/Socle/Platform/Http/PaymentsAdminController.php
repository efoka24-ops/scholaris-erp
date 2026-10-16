<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\ManualPayments;
use Scholaris\Modules\Socle\Platform\Application\SubscriptionPayments;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Paiements d'abonnement côté plateforme : enregistrement manuel (espèces, virement) avec reçu, annulation motivée,
 * reçu d'un paiement confirmé. Écritures : platform.payments.manage avec 2FA récente.
 */
final class PaymentsAdminController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly ManualPayments $manual,
        private readonly SubscriptionPayments $payments,
        private readonly ClientLifecycle $clients,
        private readonly PlatformDb $db,
    ) {
    }

    /** POST /platform/payments { tenant_id, app_key, months, channel: cash|bank_transfer, amount_xaf?, note? } */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.payments.manage', true);
        $input = Input::of($request, ['tenant_id', 'app_key', 'months', 'channel', 'amount_xaf', 'note']);
        $tenantId = $input->text('tenant_id', 1, 40, true, '/^[A-Za-z0-9_\-]+$/');
        $appKey = $input->text('app_key', 2, 20, true, '/^[a-z][a-z0-9_]+$/');
        $months = $input->integer('months', 1, SubscriptionPayments::MAX_MONTHS);
        $channel = $input->choice('channel', ManualPayments::CHANNELS);
        $amount = $input->has('amount_xaf') && $input->raw('amount_xaf') !== null ? $input->integer('amount_xaf', 1, 100000000) : null;
        $note = $input->longText('note', 500);
        $input->validate();
        $this->clients->assertNotArchived($this->clients->show((string) $tenantId));

        $payment = $this->manual->record((string) $tenantId, (string) $appKey, (int) $months, (string) $channel, $amount, $note, $principal->userId, PlatformGuard::ip($request));

        return $this->responder->json(['data' => $payment], 201);
    }

    /** POST /platform/payments/{id}/cancel { reason } */
    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.payments.manage', true);
        $input = Input::of($request, ['reason']);
        $reason = $input->longText('reason', 500, true);
        $input->validate();

        return $this->responder->json(['data' => $this->manual->cancel(PlatformGuard::param($request, 'id'), (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** GET /platform/payments/{id}/receipt */
    public function receipt(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.payments.read');
        $id = PlatformGuard::param($request, 'id');
        $tenantId = $this->db->scalar('SELECT tenant_id FROM platform_payments WHERE id = ?', [$id]);
        if (!is_string($tenantId)) {
            throw HttpException::notFound('Paiement introuvable.');
        }

        return $this->responder->json(['data' => $this->payments->receipt($tenantId, $id) + ['tenant_name' => $this->clients->tenant($tenantId)['name']]]);
    }
}
