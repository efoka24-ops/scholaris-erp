<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Tests\Support\Documents\DocumentsTestCase;

final class RegistryAndRevocationTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        $this->bootDocuments();
    }

    public function testRevocationSansMotifRefuseeEtJamaisDeSuppression(): void
    {
        $doc = $this->issueViaApi();

        foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => str_repeat('x', 501)]] as $body) {
            self::assertSame(422, $this->api('POST', '/documents/' . $doc['id'] . '/revoke', 'alice', 't1', 'e1', $body)[0]->getStatusCode());
        }
        self::assertSame('valid', $this->api('GET', '/documents/' . $doc['id'], 'alice', 't1')[1]['data']['status']);

        [$ok, $json] = $this->api('POST', '/documents/' . $doc['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Doublon']);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('revoked', $json['data']['status']);
        self::assertSame('Doublon', $json['data']['revoke_reason']);
        self::assertSame('alice', $json['data']['revoked_by']);

        self::assertSame(409, $this->api('POST', '/documents/' . $doc['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Encore'])[0]->getStatusCode());
        self::assertSame(409, $this->api('POST', '/documents/' . $doc['id'] . '/duplicate', 'alice', 't1')[0]->getStatusCode());
        self::assertSame(405, $this->api('DELETE', '/documents/' . $doc['id'], 'alice', 't1')[0]->getStatusCode());
        self::assertSame(1, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM documents')->fetchColumn());
    }

    public function testRevocationEstJournalisee(): void
    {
        $doc = $this->issueViaApi();
        $this->api('POST', '/documents/' . $doc['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Doublon']);

        $actions = $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE tenant_id = 't1' ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('document.issued', $actions);
        self::assertContains('document.revoked', $actions);
    }

    public function testRemplacementInvalideRefuse(): void
    {
        $a = $this->issueViaApi('p1', key: 'cle-emission-0001');
        $b = $this->issueViaApi('p2', key: 'cle-emission-0002');

        self::assertSame(422, $this->api('POST', '/documents/' . $a['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'x', 'replaced_by' => $b['id']])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/documents/' . $a['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'x', 'replaced_by' => $a['id']])[0]->getStatusCode());
    }

    public function testRegistreFiltreParListeBlanche(): void
    {
        $this->api('POST', '/document-types', 'alice', 't1', 'e1', ['code' => 'bulletin', 'label' => 'Bulletin', 'category' => 'bulletin', 'type_letter' => 'B', 'variables' => ['moyenne']]);
        $tpl = $this->api('POST', '/document-templates', 'alice', 't1', 'e1', ['type_code' => 'bulletin', 'locale' => 'fr', 'html' => '<p>{{holder_name}} {{moyenne}}</p>'])[1]['data'];
        $this->api('POST', '/document-templates/' . $tpl['id'] . '/validate', 'alice', 't1');

        $cert1 = $this->issueViaApi('p1', key: 'cle-emission-0001');
        $this->issueViaApi('p2', key: 'cle-emission-0002');
        $bulletin = $this->issueViaApi('p3', 'bulletin', 'cle-emission-0003', ['moyenne' => 13]);
        self::assertStringStartsWith('CLP-26-B-', $bulletin['number']);
        $this->api('POST', '/documents/' . $cert1['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Erreur']);

        $count = fn (string $query): int => count($this->api('GET', '/documents' . $query, 'alice', 't1')[1]['data']);
        self::assertSame(3, $count(''));
        self::assertSame(3, $count('?filter[view]=all'));
        self::assertSame(1, $count('?filter[view]=bulletins'));
        self::assertSame(2, $count('?filter[view]=certificates'));
        self::assertSame(1, $count('?filter[view]=revoked'));
        self::assertSame(1, $count('?filter[subject_person_id]=p2'));
        self::assertSame(2, $count('?filter[status]=valid'));

        $sorted = $this->api('GET', '/documents?sort=number', 'alice', 't1')[1]['data'];
        self::assertSame(['CLP-26-A-000001', 'CLP-26-A-000002', 'CLP-26-B-000001'], array_column($sorted, 'number'));
        $page = $this->api('GET', '/documents?per_page=2&sort=-number', 'alice', 't1')[1];
        self::assertCount(2, $page['data']);
        self::assertNotNull($page['meta']['next_cursor']);
        $next = $this->api('GET', '/documents?per_page=2&sort=-number&cursor=' . $page['meta']['next_cursor'], 'alice', 't1')[1];
        self::assertCount(1, $next['data']);
        self::assertNull($next['meta']['next_cursor']);

        // Liste blanche : filtre, tri et vue inconnus refusés (aucune injection SQL possible).
        foreach (['?filter[content]=x', '?filter[tenant_id]=t2', '?sort=content', '?sort=number;DROP', '?filter[view]=tout', '?filter[status]=valid\' OR 1=1'] as $query) {
            $code = $this->api('GET', '/documents' . str_replace(' ', '%20', $query), 'alice', 't1')[0]->getStatusCode();
            self::assertContains($code, [422, 200], $query);
            if ($code === 200) {
                self::assertSame(0, $count(str_replace(' ', '%20', $query)), $query);
            }
        }
        self::assertSame(422, $this->api('GET', '/documents?filter[content]=x', 'alice', 't1')[0]->getStatusCode());
        self::assertSame(422, $this->api('GET', '/documents?sort=content', 'alice', 't1')[0]->getStatusCode());
        self::assertSame(422, $this->api('GET', '/documents?filter[view]=tout', 'alice', 't1')[0]->getStatusCode());

        // La liste n'expose jamais le contenu rendu ni le client.
        $first = $this->api('GET', '/documents', 'alice', 't1')[1]['data'][0];
        self::assertArrayNotHasKey('content', $first);
        self::assertArrayNotHasKey('tenant_id', $first);
        self::assertArrayNotHasKey('data_json', $first);
    }

    public function testExportCsvDuRegistre(): void
    {
        $this->issueViaApi('p1', key: 'cle-emission-0001');
        $this->issueViaApi('p2', key: 'cle-emission-0002');

        [$res] = $this->api('GET', '/documents/export?filter[subject_person_id]=p1', 'alice', 't1');
        $csv = (string) $res->getBody();

        self::assertSame(200, $res->getStatusCode());
        self::assertStringStartsWith('text/csv', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $res->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('CLP-26-A-000001', $csv);
        self::assertStringNotContainsString('CLP-26-A-000002', $csv);
        self::assertStringContainsString('Nadia Fotso', $csv);
        self::assertSame(422, $this->api('GET', '/documents/export?filter[content]=x', 'alice', 't1')[0]->getStatusCode());
    }
}
