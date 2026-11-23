<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Tests\Support\Messaging\MessagingTestCase;

/** CRUD des modèles et des annonces, permissions, validation. */
final class TemplatesAndAnnouncementsTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        $this->bootMessaging();
        $this->permissions->grant('reader', 'messaging.read');
        $this->permissions->grant('sender', 'messaging.send');
        $this->access->grant('reader', 'e1');
        $this->access->grant('sender', 'e1');
    }

    /** @return array<string, mixed> */
    private function template(string $body = 'Bonjour :first_name'): array
    {
        return ['template_key' => 'welcome', 'locale' => 'fr', 'channel' => 'sms', 'body' => $body];
    }

    public function testCycleDeVieDUnModele(): void
    {
        [$created, $body] = $this->api('POST', '/message-templates', 'alice', 't1', 'e1', $this->template('Bonjour :first_name, solde :balance'));
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('establishment', $body['data']['scope']);
        self::assertSame(['first_name', 'balance'], $body['data']['variables']);
        self::assertArrayNotHasKey('tenant_id', $body['data']);
        $id = $body['data']['id'];

        $patched = $this->api('PATCH', '/message-templates/' . $id, 'alice', 't1', 'e1', ['body' => 'Salut :last_name', 'subject' => null]);
        self::assertSame('Salut :last_name', $patched[1]['data']['body']);
        self::assertSame(['last_name'], $patched[1]['data']['variables']);
        self::assertSame(200, $this->code('GET', '/message-templates/' . $id, 'alice', 't1', 'e1'));

        self::assertSame(200, $this->code('DELETE', '/message-templates/' . $id, 'alice', 't1', 'e1'));
        self::assertSame(404, $this->code('GET', '/message-templates/' . $id, 'alice', 't1', 'e1'));
    }

    public function testVariablesNonAutoriseesRefuseesALEcriture(): void
    {
        [$response, $body] = $this->api('POST', '/message-templates', 'alice', 't1', 'e1', $this->template('Code :password'));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(':password', $body['errors']['body'][0]);
        $id = $this->api('POST', '/message-templates', 'alice', 't1', 'e1', $this->template())[1]['data']['id'];
        self::assertSame(422, $this->code('PATCH', '/message-templates/' . $id, 'alice', 't1', 'e1', ['body' => ':token']));
        self::assertSame(422, $this->code('PATCH', '/message-templates/' . $id, 'alice', 't1', 'e1', ['locale' => 'en']), 'clé, langue et canal sont figés');
    }

    public function testValidationDesChamps(): void
    {
        foreach ([
            ['template_key' => 'Mauvaise Clé'], ['locale' => 'de'], ['channel' => 'fax'], ['body' => ''], ['tenant_id' => 't2'],
        ] as $override) {
            self::assertSame(422, $this->code('POST', '/message-templates', 'alice', 't1', 'e1', $override + $this->template()), json_encode($override, JSON_THROW_ON_ERROR));
        }
        self::assertSame(422, $this->code('POST', '/message-templates', 'alice', 't1', 'e1', []), 'objet vide');
    }

    public function testDoublonRefuse(): void
    {
        self::assertSame(201, $this->code('POST', '/message-templates', 'alice', 't1', 'e1', $this->template()));
        self::assertSame(409, $this->code('POST', '/message-templates', 'alice', 't1', 'e1', $this->template()));
        self::assertSame(201, $this->code('POST', '/message-templates', 'alice', 't1', 'e2', $this->template()), 'autre établissement');
    }

    public function testLesModelesDeLaPlateformeSontVisiblesEnLectureSeule(): void
    {
        $list = $this->api('GET', '/message-templates?filter[template_key]=payment_receipt', 'alice', 't1', 'e1')[1]['data'];
        self::assertCount(2, $list);
        self::assertSame('platform', $list[0]['scope']);

        $platformId = $list[0]['id'];
        self::assertSame(403, $this->code('PATCH', '/message-templates/' . $platformId, 'alice', 't1', 'e1', ['body' => 'piraté']));
        self::assertSame(403, $this->code('DELETE', '/message-templates/' . $platformId, 'alice', 't1', 'e1'));
    }

    public function testPaginationParCurseur(): void
    {
        [, $page1] = $this->api('GET', '/message-templates?per_page=4', 'alice', 't1', 'e1');
        self::assertCount(4, $page1['data']);
        self::assertNotNull($page1['meta']['next_cursor']);
        [, $page2] = $this->api('GET', '/message-templates?per_page=4&cursor=' . $page1['meta']['next_cursor'], 'alice', 't1', 'e1');
        self::assertCount(4, $page2['data']);
        self::assertEmpty(array_intersect(array_column($page1['data'], 'id'), array_column($page2['data'], 'id')));
        self::assertSame(422, $this->code('GET', '/message-templates?filter[body]=x', 'alice', 't1', 'e1'));
    }

    public function testPermissionsRefusParDefaut(): void
    {
        $id = $this->api('POST', '/message-templates', 'alice', 't1', 'e1', $this->template())[1]['data']['id'];

        // carol n'a aucun droit
        foreach ([['GET', '/message-templates'], ['POST', '/message-templates'], ['GET', '/messages'], ['POST', '/messages'], ['POST', '/messages/audience-preview'], ['GET', '/announcements'], ['POST', '/announcements'], ['GET', '/sms-usage'], ['DELETE', '/message-templates/' . $id]] as [$method, $path]) {
            self::assertSame(403, $this->code($method, $path, 'carol', 't1', 'e1', $method === 'GET' || $method === 'DELETE' ? null : ['x' => 1]), $method . ' ' . $path);
        }
        // lecteur : lit, n'écrit pas
        self::assertSame(200, $this->code('GET', '/message-templates', 'reader', 't1', 'e1'));
        self::assertSame(200, $this->code('GET', '/sms-usage', 'reader', 't1', 'e1'));
        self::assertSame(403, $this->code('POST', '/message-templates', 'reader', 't1', 'e1', $this->template()));
        self::assertSame(403, $this->code('POST', '/announcements', 'reader', 't1', 'e1', ['title' => 'a', 'body' => 'b']));
        // expéditeur : envoie, ne gère pas les modèles
        self::assertSame(403, $this->code('POST', '/message-templates', 'sender', 't1', 'e1', $this->template()));
        self::assertSame(201, $this->code('POST', '/announcements', 'sender', 't1', 'e1', ['title' => 'a', 'body' => 'b']));
        self::assertSame(200, $this->code('GET', '/message-templates', 'sender', 't1', 'e1'));
    }

    public function testCycleDeVieDUneAnnonce(): void
    {
        [$created, $body] = $this->api('POST', '/announcements', 'alice', 't1', 'e1', ['title' => 'Rentrée', 'body' => 'Le 5 octobre.', 'expires_at' => '2026-10-20T00:00:00+01:00']);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('2026-10-08T09:00:00+01:00', $body['data']['published_at']);
        self::assertArrayNotHasKey('tenant_id', $body['data']);
        $id = $body['data']['id'];

        self::assertSame('Rentrée 2', $this->api('PATCH', '/announcements/' . $id, 'alice', 't1', 'e1', ['title' => 'Rentrée 2'])[1]['data']['title']);
        self::assertCount(1, $this->api('GET', '/announcements', 'alice', 't1', 'e1')[1]['data']);
        self::assertSame(200, $this->code('DELETE', '/announcements/' . $id, 'alice', 't1', 'e1'));
        self::assertSame(404, $this->code('GET', '/announcements/' . $id, 'alice', 't1', 'e1'));
        self::assertSame(422, $this->code('POST', '/announcements', 'alice', 't1', 'e1', ['title' => 'x']));
        self::assertSame(422, $this->code('POST', '/announcements', 'alice', 't1', 'e1', ['title' => 'x', 'body' => 'y', 'published_at' => '2020-01-01T00:00:00+01:00']));
    }

    public function testLesEcrituresSontJournaliseesDansLAudit(): void
    {
        $this->api('POST', '/message-templates', 'alice', 't1', 'e1', $this->template());
        $this->api('POST', '/announcements', 'alice', 't1', 'e1', ['title' => 'a', 'body' => 'b']);

        $actions = $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE tenant_id = 't1' ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['message_template.created', 'announcement.created'], $actions);
    }
}
