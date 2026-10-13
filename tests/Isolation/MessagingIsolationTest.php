<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Messaging\MessagingTestCase;

/** Aucune fuite entre clients ni entre établissements pour modèles, messages, livraisons, annonces et compteurs. */
final class MessagingIsolationTest extends MessagingTestCase
{
    /** @var array<string, string> */
    private array $ids = [];
    private string $messageId = '';

    protected function setUp(): void
    {
        $this->bootMessaging();
        $this->parent('p1', 'Marie', '+237670000001');
        $this->people->add('t2', 'p1', 'Autre', 'Client', '+237679999999');

        $this->ids['/message-templates'] = $this->api('POST', '/message-templates', 'alice', 't1', 'e1', ['template_key' => 'note', 'locale' => 'fr', 'channel' => 'sms', 'body' => 'Note :first_name'])[1]['data']['id'];
        $this->ids['/announcements'] = $this->api('POST', '/announcements', 'alice', 't1', 'e1', ['title' => 'T', 'body' => 'B'])[1]['data']['id'];
        $this->messageId = $this->api('POST', '/messages', 'alice', 't1', 'e1', ['template_key' => 'note', 'audience' => ['type' => 'person', 'id' => 'p1']])[1]['data']['id'];
        $this->ids['/messages'] = $this->messageId;
        $this->api('POST', '/messages/' . $this->messageId . '/send', 'alice', 't1', 'e1');
    }

    public function testLIdentifiantDUnAutreClientOuDUnAutreEtablissementDonne404(): void
    {
        foreach ($this->ids as $path => $id) {
            self::assertSame(404, $this->code('GET', $path . '/' . $id, 'bob', 't2', 'e3'), 'GET ' . $path . ' autre client');
            self::assertSame(404, $this->code('GET', $path . '/' . $id, 'alice', 't1', 'e2'), 'GET ' . $path . ' autre établissement');
        }
        self::assertSame(404, $this->code('PATCH', '/message-templates/' . $this->ids['/message-templates'], 'bob', 't2', 'e3', ['body' => 'piraté']));
        self::assertSame(404, $this->code('DELETE', '/message-templates/' . $this->ids['/message-templates'], 'bob', 't2', 'e3'));
        self::assertSame(404, $this->code('PATCH', '/announcements/' . $this->ids['/announcements'], 'bob', 't2', 'e3', ['title' => 'piraté']));
        self::assertSame(404, $this->code('DELETE', '/announcements/' . $this->ids['/announcements'], 'alice', 't1', 'e2'));
        self::assertSame(404, $this->code('GET', '/messages/' . $this->messageId . '/deliveries', 'bob', 't2', 'e3'));
        self::assertSame(404, $this->code('POST', '/messages/' . $this->messageId . '/send', 'bob', 't2', 'e3'));
        self::assertSame(200, $this->code('GET', '/message-templates/' . $this->ids['/message-templates'], 'alice', 't1', 'e1'));
    }

    public function testLesListesNeContiennentQueLesDonneesDuProprietaire(): void
    {
        foreach (['/announcements', '/messages'] as $path) {
            self::assertSame([], $this->api('GET', $path, 'bob', 't2', 'e3')[1]['data'], $path . ' autre client');
            self::assertSame([], $this->api('GET', $path, 'alice', 't1', 'e2')[1]['data'], $path . ' autre établissement');
            self::assertCount(1, $this->api('GET', $path, 'alice', 't1', 'e1')[1]['data'], $path . ' propriétaire');
        }
        $keys = array_column($this->api('GET', '/message-templates', 'bob', 't2', 'e3')[1]['data'], 'template_key');
        self::assertNotContains('note', $keys, 'Le modèle d\'un client ne doit pas apparaître chez un autre.');
        self::assertContains('payment_receipt', $keys, 'Les modèles de la plateforme restent visibles.');
    }

    public function testLeComptageDesSmsEstCloisonne(): void
    {
        self::assertCount(1, $this->api('GET', '/sms-usage', 'alice', 't1', 'e1')[1]['data']);
        self::assertSame([], $this->api('GET', '/sms-usage', 'alice', 't1', 'e2')[1]['data']);
        self::assertSame([], $this->api('GET', '/sms-usage', 'bob', 't2', 'e3')[1]['data']);
    }

    public function testUnUtilisateurSansDroitSurLEtablissementEstRefuse(): void
    {
        // bob (t2) n'a aucun droit sur e1 ; alice n'a pas de droit sur e3
        self::assertSame(403, $this->code('GET', '/messages', 'bob', 't2', 'e1'));
        self::assertSame(403, $this->code('GET', '/messages', 'alice', 't1', 'e3'));
        self::assertSame(401, $this->call('GET', '/api/v1/messages', ['X-Establishment-Id' => 'e1'])->getStatusCode());
    }

    public function testLeMessengerRespecteLeClientDePersonne(): void
    {
        $this->people->add('t2', 'p9', 'Zed', 'Autre', '+237679999998');

        $result = $this->messenger()->send('t1', 'e1', 'p9', 'note');

        self::assertSame('blocked', $result->status);
        self::assertSame([], array_filter($this->sms->sent, static fn (array $s): bool => $s['to'] === '+237679999998'));
    }
}
