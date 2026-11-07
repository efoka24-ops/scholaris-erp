<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Messaging\Contracts\NotificationChannelPreferences;
use Tests\Support\Messaging\ChatTestCase;

/**
 * C-113 : conversations « Support SCHOLARIS » (établissement <-> plateforme), cloisonnement du support, boîte de réception
 * triée par dernier message, compteur de non-lus, apprenants proposés, notifications limitées à une par heure.
 * Acteurs : dora (direction de e1, messaging.support), root (plateforme, boîte du support), agent (plateforme, sans ouverture).
 */
final class SupportConversationsTest extends ChatTestCase
{
    private SupportAccountsStub $accounts;

    protected function setUp(): void
    {
        $this->bootChat();
        $this->accounts = new SupportAccountsStub();
        $this->kernel->container()->set(AccountAdministration::class, $this->accounts);
        $this->permissions->grant('dora', 'messaging.support');
        $this->permissions->grant('root', 'platform.access', 'platform.support.manage', 'platform.support.open');
        $this->permissions->grant('agent', 'platform.access', 'platform.support.manage');
        $this->permissions->grant('nobody', 'platform.access');
        $this->world->user('t1', 'dora', 'dir1');
    }

    /** @return array<string, mixed> */
    private function openSupport(string $user = 'dora', string $subject = 'Bulletins introuvables'): array
    {
        [$response, $body] = $this->api('POST', '/support-conversations', $user, 't1', 'e1', ['subject' => $subject, 'body' => 'Bonjour, les bulletins ne s\'affichent pas.']);
        self::assertSame(201, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));

        return $body['data'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function platform(string $method, string $path, string $user = 'root', ?array $body = null): array
    {
        return $this->api($method, '/platform/support' . $path, $user, 'platform', null, $body);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->connections()->pdo()->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    public function testUnAdministrateurOuvreUneConversationAvecLeSupport(): void
    {
        $conversation = $this->openSupport();

        self::assertSame('support', $conversation['kind']);
        self::assertSame('open', $conversation['support_status']);
        self::assertNull($conversation['learner_id']);
        self::assertSame([['person_id' => 'dir1', 'name' => 'Dora Bello', 'chat_role' => 'client_admin']], $conversation['participants']);
        self::assertArrayNotHasKey('support_assignee', $conversation);
        self::assertSame([$conversation['id']], array_column($this->api('GET', '/conversations?filter[kind]=support', 'dora', 't1', 'e1')[1]['data'], 'id'));

        self::assertSame(403, $this->code('POST', '/support-conversations', 'paula', 't1', 'e1', ['subject' => 'x', 'body' => 'y']), 'parent sans messaging.support');
        self::assertSame(422, $this->code('POST', '/support-conversations', 'dora', 't1', 'e1', ['subject' => 'x', 'body' => 'y', 'tenant_id' => 't2']));
        self::assertSame(422, $this->code('POST', '/conversations/' . $conversation['id'] . '/messages', 'dora', 't1', 'e1', ['body' => 'x', 'attachments' => ['doc-kid1']]), 'pas de pièce jointe au support');
    }

    public function testLeSupportNeVoitQueLesConversationsDeSupport(): void
    {
        $internal = $this->openConversation();
        $support = $this->openSupport();

        [$list, $body] = $this->platform('GET', '/conversations');
        self::assertSame(200, $list->getStatusCode());
        self::assertSame([$support['id']], array_column($body['data'], 'id'));
        self::assertSame('t1', $body['data'][0]['tenant_id']);
        self::assertSame(1, $body['data'][0]['unread_count']);

        foreach ([['GET', ''], ['GET', '/messages'], ['POST', '/messages'], ['POST', '/status'], ['POST', '/assign']] as [$method, $suffix]) {
            $payload = match ($suffix) {
                '/messages' => ['body' => 'Intrusion'],
                '/status' => ['status' => 'resolved'],
                '/assign' => ['user_id' => null],
                default => null,
            };
            self::assertSame(404, $this->platform($method, '/conversations/' . $internal . $suffix, 'root', $method === 'POST' ? $payload : null)[0]->getStatusCode(), 'conversation interne : ' . $method . $suffix);
        }
        self::assertSame(['Bonjour, puis-je vous rencontrer ?'], array_column($this->api('GET', '/conversations/' . $internal . '/messages', 'paula', 't1', 'e1')[1]['data'], 'body'), 'intacte');
    }

    public function testEchangeStatutsEtNonLus(): void
    {
        $id = $this->openSupport()['id'];

        [$replied, $reply] = $this->platform('POST', '/conversations/' . $id . '/messages', 'root', ['body' => 'Nous regardons tout de suite.']);
        self::assertSame(201, $replied->getStatusCode(), json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame('platform', $reply['data']['sender_side']);
        self::assertSame('pending', $this->platform('GET', '/conversations/' . $id)[1]['data']['support_status']);

        self::assertSame(['unread' => 1], $this->api('GET', '/conversation-unread', 'dora', 't1', 'e1')[1]['data']);
        $seen = $this->api('GET', '/conversations/' . $id . '/messages', 'dora', 't1', 'e1')[1]['data'][0];
        self::assertSame('platform', $seen['sender_side']);
        self::assertNull($seen['sender_id'], 'l\'agent de la plateforme n\'est pas nommé');
        self::assertSame('Nous regardons tout de suite.', $seen['body']);
        self::assertSame(['unread' => 0], $this->api('GET', '/conversation-unread', 'dora', 't1', 'e1')[1]['data']);
        $push = $this->scalar("SELECT COUNT(*) FROM message_deliveries WHERE recipient_id = 'dir1' AND channel = 'push'");
        self::assertSame(1, $push, 'notification dans l\'application');

        $this->api('POST', '/conversations/' . $id . '/messages', 'dora', 't1', 'e1', ['body' => 'Merci, toujours rien.']);
        $row = $this->platform('GET', '/conversations?filter[status]=open')[1]['data'][0];
        self::assertSame('open', $row['support_status']);
        self::assertSame(1, $row['unread_count']);
        self::assertCount(3, $this->platform('GET', '/conversations/' . $id . '/messages')[1]['data']);
        self::assertSame(0, $this->platform('GET', '/conversations')[1]['data'][0]['unread_count']);

        self::assertSame('resolved', $this->platform('POST', '/conversations/' . $id . '/status', 'agent', ['status' => 'resolved'])[1]['data']['support_status']);
        self::assertSame(422, $this->platform('POST', '/conversations/' . $id . '/status', 'agent', ['status' => 'perdu'])[0]->getStatusCode());
        self::assertSame([], $this->platform('GET', '/conversations?filter[status]=open')[1]['data']);
        self::assertSame(422, $this->platform('GET', '/conversations?filter[status]=autre')[0]->getStatusCode());
        $audited = $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'platform.support_status_changed'");
        self::assertGreaterThanOrEqual(1, $audited);
    }

    public function testAssignationAUnMembreDuSupport(): void
    {
        $id = $this->openSupport()['id'];
        $this->accounts->add('platform', 'platform_admin', 'root', 'proot');
        $this->accounts->add('platform', 'platform_admin', 'old', 'pold', 'locked');

        self::assertSame(['root'], array_column($this->platform('GET', '/agents')[1]['data'], 'user_id'));
        self::assertSame('root', $this->platform('POST', '/conversations/' . $id . '/assign', 'root', ['user_id' => 'root'])[1]['data']['assignee']);
        self::assertSame(422, $this->platform('POST', '/conversations/' . $id . '/assign', 'root', ['user_id' => 'old'])[0]->getStatusCode(), 'compte bloqué');
        self::assertSame([$id], array_column($this->platform('GET', '/conversations?filter[assignee]=root')[1]['data'], 'id'));
        self::assertSame([], $this->platform('GET', '/conversations?filter[assignee]=none')[1]['data']);
        self::assertNull($this->platform('POST', '/conversations/' . $id . '/assign', 'root', ['user_id' => null])[1]['data']['assignee']);
    }

    public function testLeSuperAdminOuvreVersLesAdministrateursDUnClient(): void
    {
        $this->accounts->add('t1', 'super_admin', 'dora', 'dir1');
        $this->accounts->add('t1', 'super_admin', 'gone', 'par2', 'locked');

        [$opened, $body] = $this->platform('POST', '/conversations', 'root', ['tenant_id' => 't1', 'establishment_id' => 'e1', 'subject' => 'Mise à jour', 'body' => 'Une mise à jour arrive ce soir.']);
        self::assertSame(201, $opened->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame('pending', $body['data']['support_status']);
        self::assertSame('platform', $body['data']['opened_by']);
        self::assertSame(['dir1'], array_column($body['data']['participants'], 'person_id'));

        $inbox = $this->api('GET', '/conversations', 'dora', 't1', 'e1')[1]['data'];
        self::assertSame([$body['data']['id']], array_column($inbox, 'id'));
        self::assertSame(1, $inbox[0]['unread_count']);
        self::assertSame('support', $inbox[0]['created_by']);
        self::assertSame([], $this->api('GET', '/conversations', 'pierre', 't1', 'e1')[1]['data']);

        self::assertSame(403, $this->platform('POST', '/conversations', 'agent', ['tenant_id' => 't1', 'establishment_id' => 'e1', 'subject' => 'x', 'body' => 'y'])[0]->getStatusCode(), 'ouverture réservée');
        self::assertSame(422, $this->platform('POST', '/conversations', 'root', ['tenant_id' => 't1', 'establishment_id' => 'e3', 'subject' => 'x', 'body' => 'y'])[0]->getStatusCode(), 'établissement d\'un autre client');
        self::assertSame(422, $this->platform('POST', '/conversations', 'root', ['tenant_id' => 't2', 'establishment_id' => 'e3', 'subject' => 'x', 'body' => 'y'])[0]->getStatusCode(), 'aucun administrateur actif');
    }

    public function testPermissionsDuBackOffice(): void
    {
        $this->openSupport();

        self::assertSame(403, $this->platform('GET', '/conversations', 'nobody')[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/platform/support/conversations', 'dora', 't1')[0]->getStatusCode(), 'compte d\'un client');
        self::assertSame(401, $this->call('GET', '/api/v1/platform/support/conversations')->getStatusCode());
    }

    public function testIsolationDesConversationsDeSupportCoteClients(): void
    {
        $id = $this->openSupport()['id'];

        self::assertSame(404, $this->code('GET', '/conversations/' . $id, 'bob', 't2', 'e3'));
        self::assertSame(404, $this->code('POST', '/conversations/' . $id . '/messages', 'bob', 't2', 'e3', ['body' => 'Intrusion']));
        self::assertSame(404, $this->code('GET', '/conversations/' . $id, 'tom', 't1', 'e1'), 'autre membre de l\'établissement, non participant');
        $this->access->grant('dora', 'e2');
        self::assertSame(404, $this->code('GET', '/conversations/' . $id, 'dora', 't1', 'e2'), 'autre établissement');
    }

    public function testBoiteTrieeParDernierMessageEtApprenantsProposes(): void
    {
        $first = $this->openConversation();
        $second = $this->openConversation('paula', ['subject' => 'Deuxième sujet']);
        self::assertSame([$second, $first], array_column($this->api('GET', '/conversations', 'paula', 't1', 'e1')[1]['data'], 'id'));

        $this->clock->set('2026-10-08T10:00:00+01:00');
        $this->api('POST', '/conversations/' . $first . '/messages', 'tom', 't1', 'e1', ['body' => 'Réponse']);
        [, $page] = $this->api('GET', '/conversations?per_page=1', 'paula', 't1', 'e1');
        self::assertSame([$first], array_column($page['data'], 'id'));
        self::assertSame([$second], array_column($this->api('GET', '/conversations?per_page=1&cursor=' . $page['meta']['next_cursor'], 'paula', 't1', 'e1')[1]['data'], 'id'));

        self::assertSame([['learner_id' => 'kid1', 'name' => 'Kevin Foka']], $this->api('GET', '/conversation-learners', 'paula', 't1', 'e1')[1]['data']);
        self::assertSame(['kid1'], array_column($this->api('GET', '/conversation-learners', 'tom', 't1', 'e1')[1]['data'], 'learner_id'));
        self::assertSame(['unread' => 1], $this->api('GET', '/conversation-unread', 'paula', 't1', 'e1')[1]['data']);
        self::assertSame(403, $this->code('GET', '/conversation-unread', 'carol', 't1', 'e1'));
    }

    public function testParDefautNotificationDansLApplicationSeulement(): void
    {
        $this->openConversation();

        $channels = $this->connections()->pdo()->query("SELECT channel FROM message_deliveries WHERE recipient_id = 'tea1'") ?: null;
        self::assertNotNull($channels);
        self::assertSame(['push'], $channels->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testSmsSelonLesReglagesAuPlusUnParConversationEtParHeure(): void
    {
        $this->kernel->container()->set(NotificationChannelPreferences::class, new class () implements NotificationChannelPreferences {
            public function extraChannelsFor(string $tenantId, string $personId): array
            {
                return ['sms', 'fax'];
            }
        });
        $conversation = $this->openConversation();
        $this->api('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e1', ['body' => 'Deuxième message']);
        $sms = fn (): int => $this->scalar("SELECT COUNT(*) FROM message_deliveries WHERE recipient_id = 'tea1' AND channel = 'sms'");
        self::assertSame(1, $sms());
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM message_deliveries WHERE recipient_id = 'tea1' AND channel = 'push'"));

        $this->clock->set('2026-10-08T10:01:00+01:00');
        $this->api('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e1', ['body' => 'Une heure plus tard']);
        self::assertSame(2, $sms());
        self::assertStringNotContainsString('heure plus tard', json_encode($this->sms->sent, JSON_THROW_ON_ERROR));
    }
}
