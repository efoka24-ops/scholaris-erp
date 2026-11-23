<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\Support\Platform\PlatformTestCase;

/** C-112 point 4 : journaux des SMS et e-mails, relance, statistiques internes, envoi direct (aperçu, plafond, 2FA). */
final class PlatformMessagesMonitorTest extends PlatformTestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->tenant = $this->client('Institut du Littoral')['tenant'];
        $this->permissions->grant(self::ROOT, 'platform', 'platform.messages.read', 'platform.messages.retry', 'platform.messages.send', 'platform.messages.broadcast');
    }

    /** Administrateur (super_admin) réel du client, invité par la plateforme. */
    private function admin(string $email, string $phone): string
    {
        [$response, $json] = $this->api('POST', '/platform/clients/' . $this->tenant . '/administrators', self::ROOT, null, ['first_name' => 'Awa', 'last_name' => 'Ngo', 'email' => $email, 'phone' => $phone]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $json['data']['id'];
    }

    public function testJournalMasqueRelanceEtStatistiques(): void
    {
        $this->admin('awa@client.test', '+237690001234');
        $person = (string) $this->connections()->pdo()->query("SELECT id FROM people WHERE email = 'awa@client.test'")->fetchColumn();
        $now = '2026-10-09T09:00:00+01:00';
        $pdo = $this->connections()->pdo();
        $pdo->prepare('INSERT INTO messages (id, tenant_id, establishment_id, template_key, channels, variables, audience, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['M1', $this->tenant, 'E1', 'absence_alert', '["sms"]', '{}', '{}', 'sent', $now, $now]);
        $pdo->prepare('INSERT INTO message_deliveries (id, tenant_id, establishment_id, message_id, recipient_id, channel, status, reason, provider_reference, attempts, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['01DELIVERY0000000000000001', $this->tenant, 'E1', 'M1', $person, 'sms', 'failed', 'provider_failed', 'teeeotp-42', 1, $now, $now]);
        $pdo->prepare('INSERT INTO conversations (id, tenant_id, establishment_id, subject, created_by, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['C1', $this->tenant, 'E1', 'Sortie', 'U1', 'open', $now, $now]);
        $pdo->prepare('INSERT INTO conversation_messages (id, tenant_id, establishment_id, conversation_id, sender_id, body, moderation_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['CM1', $this->tenant, 'E1', 'C1', 'U1', 'Texte privé jamais lu', 'visible', $now]);

        [$response, $journal] = $this->api('GET', '/platform/messages/deliveries?filter[channel]=sms', self::ROOT);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $journal['data'][0];
        self::assertSame('6•• •• •• 34', $row['recipient']);
        self::assertSame('absence_alert', $row['template_key']);
        self::assertSame('provider_failed', $row['reason']);
        self::assertSame('Institut du Littoral', $row['tenant_name']);
        self::assertStringNotContainsString('690001234', (string) json_encode($journal));

        self::assertSame(200, $this->api('POST', '/platform/messages/deliveries/' . $this->tenant . '/01DELIVERY0000000000000001/retry', self::ROOT, null, [])[0]->getStatusCode());
        self::assertSame(409, $this->api('POST', '/platform/messages/deliveries/' . $this->tenant . '/01DELIVERY0000000000000001/retry', self::ROOT, null, [])[0]->getStatusCode(), 'déjà remise en file');

        [, $internal] = $this->api('GET', '/platform/messages/internal', self::ROOT);
        self::assertSame([['tenant_id' => $this->tenant, 'conversations' => 1, 'messages' => 1, 'hidden' => 0, 'tenant_name' => 'Institut du Littoral']], $internal['data']);
        self::assertStringNotContainsString('Texte privé', (string) json_encode($internal));

        $this->permissions->grant('finance', 'platform', 'platform.access', 'platform.payments.read');
        self::assertSame(403, $this->api('GET', '/platform/messages/deliveries', 'finance')[0]->getStatusCode());
    }

    public function testEnvoiDirectAUnePersonneEtEnMasse(): void
    {
        $user = $this->admin('awa@client.test', '+237690001234');
        $this->admin('paul@client.test', '+237690005678');
        $person = ['target_type' => 'person', 'tenant_id' => $this->tenant, 'user_id' => $user, 'channel' => 'sms', 'subject' => 'Maintenance', 'body' => 'Coupure prévue samedi de 22 h à 23 h.'];

        [$response, $preview] = $this->api('POST', '/platform/messages/direct/preview', self::ROOT, null, $person);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $preview['data']['recipients']);
        self::assertSame(25, $preview['data']['estimated_cost_xaf']);
        self::assertSame('6•• •• •• 34', $preview['data']['sample'][0]['address']);

        // Une personne : pas besoin de double authentification récente.
        $this->secondFactor->revoke(self::ROOT);
        [$sent, $json] = $this->api('POST', '/platform/messages/direct', self::ROOT, null, $person);
        self::assertSame(201, $sent->getStatusCode(), (string) $sent->getBody());
        self::assertSame(1, $json['data']['sent']);
        $sms = $this->notifier->of('platform_direct_message');
        self::assertSame('+237690001234', $sms[0]['phone']);
        self::assertSame('Coupure prévue samedi de 22 h à 23 h.', $sms[0]['variables']['details']);

        // En masse : 2FA récente obligatoire, puis plafond.
        $bulk = ['target_type' => 'client_admins', 'tenant_id' => $this->tenant, 'channel' => 'email', 'subject' => 'Nouveautés', 'body' => 'Le module de bulletins évolue.'];
        self::assertSame(403, $this->api('POST', '/platform/messages/direct', self::ROOT, null, $bulk)[0]->getStatusCode());
        $this->secondFactor->allow(self::ROOT);
        [, $emailPreview] = $this->api('POST', '/platform/messages/direct/preview', self::ROOT, null, $bulk);
        self::assertSame(2, $emailPreview['data']['recipients']);
        self::assertStringContainsString('Nouveautés', (string) $emailPreview['data']['email_html']);
        $this->api('PATCH', '/platform/settings', self::ROOT, null, ['broadcast_max_recipients' => 1]);
        self::assertSame(409, $this->api('POST', '/platform/messages/direct', self::ROOT, null, $bulk)[0]->getStatusCode(), 'plafond dépassé');
        $this->api('PATCH', '/platform/settings', self::ROOT, null, ['broadcast_max_recipients' => 10]);
        [$ok, $done] = $this->api('POST', '/platform/messages/direct', self::ROOT, null, $bulk);
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame(2, $done['data']['sent']);

        [, $journal] = $this->api('GET', '/platform/messages/direct?filter[channel]=email', self::ROOT);
        self::assertCount(2, $journal['data']);
        self::assertContains('a•••@client.test', array_column($journal['data'], 'recipient_masked'));

        $logged = $this->connections()->pdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = ?');
        $logged->execute(['platform', 'platform.direct_message_sent']);
        self::assertSame(2, (int) $logged->fetchColumn());

        // Le support envoie à une personne mais jamais en masse.
        $this->permissions->grant('support', 'platform', 'platform.access', 'platform.messages.send');
        $this->secondFactor->allow('support');
        self::assertSame(201, $this->api('POST', '/platform/messages/direct', 'support', null, $person)[0]->getStatusCode());
        self::assertSame(403, $this->api('POST', '/platform/messages/direct', 'support', null, $bulk)[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/platform/messages/direct', self::ROOT, null, ['target_type' => 'everyone', 'channel' => 'sms', 'subject' => 'x', 'body' => 'y'])[0]->getStatusCode());
    }
}
