<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryMonitor;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Application\DirectMessages;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;

/**
 * Suivi des messages et envoi direct (C-112 point 4) : journal des SMS et e-mails des clients (destinataire masqué, statut
 * TeeeOTP, motif d'échec, relance), journal des envois directs de la plateforme, statistiques des messages internes par
 * client (comptes seulement, jamais le contenu), aperçu puis envoi direct (SMS, e-mail ou notification dans l'application) ;
 * envoi en masse : platform.messages.broadcast, double authentification récente et plafond (réglage).
 */
final class MessagesAdminController
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';
    private const ID = '/^[A-Za-z0-9:_-]{1,40}$/';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly DeliveryMonitor $deliveries,
        private readonly DirectMessages $direct,
        private readonly ClientProvisioning $clients,
        private readonly PlatformAudit $audit,
    ) {
    }

    /** GET /platform/messages/deliveries : SMS et e-mails des clients. */
    public function deliveries(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.messages.read');
        $query = ListQuery::fromRequest($request, ['channel', 'tenant_id', 'status', 'from', 'to']);
        $filters = $query->filters;
        foreach (['from', 'to'] as $date) {
            if (isset($filters[$date]) && preg_match(self::DATE, $filters[$date]) !== 1) {
                throw HttpException::validation(['filter[' . $date . ']' => ['Date attendue au format AAAA-MM-JJ.']]);
            }
        }
        $rows = $this->deliveries->journal($filters, $query->cursor, $query->perPage + 1);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = $rows[$query->perPage - 1]['id'];
        }
        $names = $this->names();

        return $this->responder->json(['data' => array_map(static fn (array $r): array => $r + ['tenant_name' => $names[$r['tenant_id']] ?? $r['tenant_id']], $rows), 'meta' => ['next_cursor' => $next]]);
    }

    /** POST /platform/messages/deliveries/{tenant}/{id}/retry : relance d'une livraison en échec (2FA récente). */
    public function retryDelivery(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.messages.retry', true);
        Input::of($request, [])->validate();
        $tenantId = PlatformGuard::param($request, 'tenant');
        $id = PlatformGuard::param($request, 'id');
        if (!$this->deliveries->retry($tenantId, $id)) {
            throw HttpException::conflict('Seule une livraison en échec se relance.');
        }
        $this->audit->record($tenantId, $principal->userId, 'platform.delivery_retried', 'message_delivery', $id, [], PlatformGuard::ip($request));

        return $this->responder->json(['data' => ['id' => $id, 'status' => 'queued']]);
    }

    /** GET /platform/messages/direct : envois directs de la plateforme. */
    public function directJournal(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.messages.read');
        $query = ListQuery::fromRequest($request, ['channel', 'tenant_id', 'status']);
        $rows = $this->direct->journal($query->filters, $query->cursor, $query->perPage + 1);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = (string) $rows[$query->perPage - 1]['id'];
        }
        $names = $this->names();

        return $this->responder->json(['data' => array_map(static fn (array $r): array => $r + ['tenant_name' => $names[(string) $r['tenant_id']] ?? $r['tenant_id']], $rows), 'meta' => ['next_cursor' => $next]]);
    }

    /** POST /platform/messages/direct/{id}/retry (2FA récente). */
    public function retryDirect(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.messages.retry', true);
        Input::of($request, [])->validate();

        return $this->responder->json(['data' => $this->direct->retry(PlatformGuard::param($request, 'id'), $principal->userId, PlatformGuard::ip($request))]);
    }

    /** GET /platform/messages/internal : messagerie interne, comptes par client. */
    public function internal(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.messages.read');
        $names = $this->names();

        return $this->responder->json(['data' => array_map(static fn (array $r): array => $r + ['tenant_name' => $names[$r['tenant_id']] ?? $r['tenant_id']], $this->deliveries->internalCounts())]);
    }

    /** POST /platform/messages/direct/preview */
    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAny($request, ['platform.messages.send', 'platform.messages.broadcast']);
        [$target, $channel, $subject, $body] = $this->message($request);

        return $this->responder->json(['data' => $this->direct->preview($target, $channel, $subject, $body)]);
    }

    /** POST /platform/messages/direct : envoi (une personne : platform.messages.send ; en masse : platform.messages.broadcast et 2FA récente). */
    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAny($request, ['platform.messages.send', 'platform.messages.broadcast']);
        [$target, $channel, $subject, $body] = $this->message($request);
        $bulk = $target['type'] !== 'person';
        if ($bulk) {
            $this->guard->platformAdmin($request, 'platform.messages.broadcast', true);
        }

        return $this->responder->json(['data' => $this->direct->send($target, $channel, $subject, $body, $principal->userId, PlatformGuard::ip($request))], 201);
    }

    /** @return array{0: array{type: string, tenant_id: ?string, user_id: ?string, app_key: ?string}, 1: string, 2: string, 3: string} */
    private function message(ServerRequestInterface $request): array
    {
        $input = Input::of($request, ['target_type', 'tenant_id', 'user_id', 'app_key', 'channel', 'subject', 'body']);
        $type = (string) $input->choice('target_type', DirectMessages::TARGETS);
        $channel = (string) $input->choice('channel', DirectMessages::CHANNELS);
        $tenant = $input->text('tenant_id', 1, 40, in_array($type, ['person', 'client_admins'], true), self::ID);
        $user = $input->text('user_id', 1, 40, $type === 'person', self::ID);
        $app = $input->text('app_key', 2, 20, $type === 'app_clients', '/^[a-z][a-z0-9_]+$/');
        $subject = (string) $input->text('subject', 1, 120);
        $body = (string) $input->longText('body', $channel === 'sms' ? 400 : 4000, true);
        $input->validate();

        return [['type' => $type, 'tenant_id' => $tenant, 'user_id' => $user, 'app_key' => $app], $channel, $subject, $body];
    }

    /** @return array<string, string> */
    private function names(): array
    {
        $names = [PlatformAudit::PLATFORM_TENANT => 'Plateforme SCHOLARIS'];
        foreach ($this->clients->tenants() as $tenant) {
            $names[$tenant['id']] = $tenant['name'];
        }

        return $names;
    }
}
