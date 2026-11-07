<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Contracts\AccountActivity;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Integrations\Contracts\GatewayStatus;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier;
use Scholaris\Modules\Socle\Messaging\Contracts\SupportDesk;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Mask;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;
use Throwable;

/**
 * Envoi direct depuis le back-office (C-112 point 4) : SMS, e-mail ou notification dans l'application (conversation du
 * support SCHOLARIS), à une personne, aux administrateurs d'un client ou aux administrateurs de tous les clients d'une
 * application. Aperçu (destinataires, coût SMS estimé), plafond d'envoi en masse (réglage), chaque envoi journalisé.
 */
final class DirectMessages
{
    public const CHANNELS = ['sms', 'email', 'app'];
    public const TARGETS = ['person', 'client_admins', 'app_clients'];
    public const TEMPLATE = 'platform_direct_message';
    /** Coût unitaire estimé d'un SMS de 160 caractères (XAF), pour l'aperçu seulement. */
    public const SMS_UNIT_COST_XAF = 25;
    private const SMS_SEGMENT = 160;

    public function __construct(
        private readonly PlatformDb $db,
        private readonly AccountActivity $activity,
        private readonly AccountAdministration $accounts,
        private readonly ClientProvisioning $clients,
        private readonly DirectNotifier $notifier,
        private readonly SupportDesk $support,
        private readonly GatewayStatus $gateways,
        private readonly PlatformSettings $settings,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /**
     * Destinataires d'une cible (comptes actifs ou en attente, jamais bloqués).
     *
     * @param array{type: string, tenant_id?: ?string, user_id?: ?string, app_key?: ?string} $target
     * @return list<array{tenant_id: string, user_id: string, person_id: string, first_name: string, phone: ?string, email: ?string, locale: string}>
     */
    public function recipients(array $target): array
    {
        $out = [];
        if ($target['type'] === 'person') {
            $account = $this->activity->account((string) ($target['tenant_id'] ?? ''), (string) ($target['user_id'] ?? ''));
            if ($account['status'] !== 'locked') {
                $out[] = ['tenant_id' => $account['tenant_id'], 'user_id' => $account['user_id'], 'person_id' => $account['person_id'], 'first_name' => $account['first_name'], 'phone' => $account['phone'], 'email' => $account['email'], 'locale' => $account['locale']];
            }
        } else {
            $tenants = $target['type'] === 'client_admins'
                ? [(string) ($target['tenant_id'] ?? '')]
                : array_map(static fn (array $r): string => (string) $r['tenant_id'], $this->db->all('SELECT tenant_id FROM tenant_applications WHERE app_key = ? AND state <> ? ORDER BY tenant_id', [(string) ($target['app_key'] ?? ''), ApplicationAccess::CLOSED]));
            foreach ($tenants as $tenantId) {
                if ($target['type'] === 'client_admins' && !$this->clients->tenantExists($tenantId)) {
                    throw HttpException::notFound('Client introuvable.');
                }
                foreach ($this->accounts->holders($tenantId, AdministratorAccounts::CLIENT_ROLE) as $holder) {
                    if ($holder['status'] !== 'locked') {
                        $out[] = ['tenant_id' => $tenantId, 'user_id' => $holder['user_id'], 'person_id' => $holder['person_id'], 'first_name' => $holder['first_name'], 'phone' => $holder['phone'], 'email' => $holder['email'], 'locale' => $holder['locale']];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array{type: string, tenant_id?: ?string, user_id?: ?string, app_key?: ?string} $target
     * @return array<string, mixed>
     */
    public function preview(array $target, string $channel, string $subject, string $body): array
    {
        $recipients = $this->recipients($target);
        $reachable = array_values(array_filter($recipients, fn (array $r): bool => $this->address($r, $channel) !== null));
        $segments = $channel === 'sms' ? max(1, (int) ceil(mb_strlen('SCHOLARIS - Bonjour Prénom, ' . $body) / self::SMS_SEGMENT)) : 0;

        return [
            'recipients' => count($recipients),
            'reachable' => count($reachable),
            'sample' => array_map(fn (array $r): array => ['tenant_id' => $r['tenant_id'], 'first_name' => $r['first_name'], 'address' => Mask::address($this->address($r, $channel))], array_slice($recipients, 0, 10)),
            'sms_segments' => $segments,
            'estimated_cost_xaf' => $channel === 'sms' ? count($reachable) * $segments * self::SMS_UNIT_COST_XAF : 0,
            'max_recipients' => $this->settings->value(PlatformSettings::BROADCAST_MAX),
            'bulk' => count($recipients) > 1,
            'email_html' => $channel === 'email' ? $this->gateways->emailPreview('SCHOLARIS : ' . $subject, 'Bonjour Prénom, ' . $body) : null,
        ];
    }

    /**
     * @param array{type: string, tenant_id?: ?string, user_id?: ?string, app_key?: ?string} $target
     * @return array{batch_id: string, recipients: int, sent: int, failed: int}
     */
    public function send(array $target, string $channel, string $subject, string $body, string $actorId, ?string $ip): array
    {
        $recipients = $this->recipients($target);
        if ($recipients === []) {
            throw HttpException::conflict('Aucun destinataire joignable pour cette cible.');
        }
        $max = $this->settings->value(PlatformSettings::BROADCAST_MAX);
        if (count($recipients) > $max) {
            throw HttpException::conflict(sprintf('Envoi en masse plafonné à %d destinataires (réglage de la plateforme) : %d visés.', $max, count($recipients)));
        }
        $batch = (string) new Ulid();
        $now = $this->clock->iso();
        $rows = [];
        foreach ($recipients as $r) {
            $row = [
                'id' => (string) new Ulid(), 'batch_id' => $batch, 'tenant_id' => $r['tenant_id'], 'recipient_user_id' => $r['user_id'],
                'recipient_masked' => Mask::address($this->address($r, $channel)), 'channel' => $channel, 'subject' => $subject, 'body' => $body,
                'status' => 'pending', 'reason' => null, 'provider_reference' => null, 'attempts' => 1, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ];
            if ($channel !== 'app') {
                [$row['status'], $row['reason'], $row['provider_reference']] = $this->deliver($r, $channel, $subject, $body);
            }
            $rows[] = $row;
        }
        if ($channel === 'app') {
            $rows = $this->notifyInApp($rows, $recipients, $subject, $body, $actorId);
        }
        foreach ($rows as $row) {
            $this->db->insert('platform_direct_messages', $row);
        }
        $sent = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'sent'));
        $this->audit->record(null, $actorId, 'platform.direct_message_sent', 'direct_message', $batch, [
            'channel' => $channel, 'target' => $target['type'], 'tenant_id' => $target['tenant_id'] ?? null, 'app_key' => $target['app_key'] ?? null,
            'recipients' => count($rows), 'sent' => $sent,
        ], $ip);

        return ['batch_id' => $batch, 'recipients' => count($rows), 'sent' => $sent, 'failed' => count($rows) - $sent];
    }

    /**
     * Journal des envois directs, le plus récent d'abord (destinataire masqué ; le texte est celui de la plateforme).
     *
     * @param array{channel?: string, tenant_id?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function journal(array $filters, ?string $beforeId, int $limit): array
    {
        $sql = 'SELECT id, batch_id, tenant_id, recipient_user_id, recipient_masked, channel, subject, status, reason, provider_reference, attempts, created_by, created_at, updated_at FROM platform_direct_messages WHERE 1 = 1';
        $params = [];
        foreach (['channel', 'tenant_id', 'status'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $sql .= ' AND ' . $key . ' = ?';
                $params[] = $filters[$key];
            }
        }
        if ($beforeId !== null) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }

        return $this->db->all($sql . sprintf(' ORDER BY id DESC LIMIT %d', max(1, min($limit, 500))), $params);
    }

    /**
     * Relance un envoi direct SMS ou e-mail en échec.
     *
     * @return array<string, mixed>
     */
    public function retry(string $id, string $actorId, ?string $ip): array
    {
        $row = $this->db->one('SELECT * FROM platform_direct_messages WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if ($row['status'] === 'sent' || $row['channel'] === 'app') {
            throw HttpException::conflict('Seul un SMS ou un e-mail en échec se relance.');
        }
        $recipient = $this->recipients(['type' => 'person', 'tenant_id' => (string) $row['tenant_id'], 'user_id' => (string) $row['recipient_user_id']])[0]
            ?? throw HttpException::conflict('Destinataire bloqué ou introuvable.');
        [$status, $reason, $reference] = $this->deliver($recipient, (string) $row['channel'], (string) $row['subject'], (string) $row['body']);
        $this->db->update('platform_direct_messages', ['status' => $status, 'reason' => $reason, 'provider_reference' => $reference, 'attempts' => (int) $row['attempts'] + 1, 'updated_at' => $this->clock->iso()], ['id' => $id]);
        $this->audit->record(null, $actorId, 'platform.direct_message_retried', 'direct_message', $id, ['status' => $status], $ip);

        return $this->db->one('SELECT id, batch_id, tenant_id, recipient_user_id, recipient_masked, channel, subject, status, reason, provider_reference, attempts, created_by, created_at, updated_at FROM platform_direct_messages WHERE id = ?', [$id]) ?? [];
    }

    /**
     * Envois directs depuis `$since`, par canal puis statut (« blocked » compté comme échec).
     *
     * @return array<string, array<string, int>>
     */
    public function countsSince(string $since): array
    {
        $out = [];
        foreach ($this->db->all('SELECT channel, status, COUNT(*) AS total FROM platform_direct_messages WHERE created_at >= ? GROUP BY channel, status', [$since]) as $row) {
            $status = $row['status'] === 'sent' ? 'sent' : 'failed';
            $out[(string) $row['channel']][$status] = ($out[(string) $row['channel']][$status] ?? 0) + (int) $row['total'];
        }

        return $out;
    }

    /** @param array{phone: ?string, email: ?string} $recipient */
    private function address(array $recipient, string $channel): ?string
    {
        return match ($channel) {
            'sms' => $recipient['phone'],
            'email' => $recipient['email'],
            default => 'app',
        };
    }

    /**
     * @param array{first_name: string, phone: ?string, email: ?string, locale: string} $recipient
     * @return array{0: string, 1: ?string, 2: ?string} statut, motif, référence du fournisseur
     */
    private function deliver(array $recipient, string $channel, string $subject, string $body): array
    {
        $address = $this->address($recipient, $channel);
        if ($address === null || $address === '') {
            return ['failed', 'no_contact', null];
        }
        try {
            $result = $this->notifier->notify(self::TEMPLATE, $recipient['locale'], $channel === 'email' ? $address : null, $channel === 'sms' ? $address : null, [
                'first_name' => $recipient['first_name'], 'subject' => $subject, 'details' => $body,
            ]);
        } catch (Throwable) {
            return ['failed', 'gateway_error', null];
        }

        return $result->status === DeliveryResult::SENT ? ['sent', null, $result->messageId] : ['failed', $result->reason ?? 'blocked', null];
    }

    /**
     * Notification dans l'application : une conversation du support SCHOLARIS par client, vers ses destinataires.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{tenant_id: string, person_id: string}> $recipients
     * @return list<array<string, mixed>>
     */
    private function notifyInApp(array $rows, array $recipients, string $subject, string $body, string $actorId): array
    {
        $byTenant = [];
        foreach ($recipients as $r) {
            $byTenant[$r['tenant_id']][] = $r['person_id'];
        }
        $status = [];
        foreach ($byTenant as $tenantId => $people) {
            $establishment = null;
            foreach ($this->clients->establishmentsOf($tenantId) as $e) {
                if ($e['closed_at'] === null) {
                    $establishment = $e['id'];
                    break;
                }
            }
            try {
                if ($establishment === null) {
                    throw HttpException::conflict('Aucun établissement ouvert.');
                }
                $this->support->openToClient($tenantId, $establishment, array_values(array_unique($people)), $actorId, mb_substr($subject, 0, 160), $body);
                $status[$tenantId] = ['sent', null];
            } catch (Throwable) {
                $status[$tenantId] = ['failed', 'in_app_unavailable'];
            }
        }

        return array_map(static fn (array $row): array => array_replace($row, ['status' => $status[$row['tenant_id']][0], 'reason' => $status[$row['tenant_id']][1]]), $rows);
    }
}
