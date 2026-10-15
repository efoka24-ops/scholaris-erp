<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Tests\Support\Documents\DocumentsTestCase;

final class TemplatesAndDuplicateTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        $this->bootDocuments();
    }

    /** @return array<string, mixed> */
    private function template(string $html, int $expect = 201): array
    {
        [$response, $json] = $this->api('POST', '/document-templates', 'alice', 't1', 'e1', ['type_code' => 'attestation_simple', 'locale' => 'fr', 'html' => $html]);
        self::assertSame($expect, $response->getStatusCode(), (string) $response->getBody());

        return $json;
    }

    public function testVariableNonAutoriseeEstRefuseeALEnregistrement(): void
    {
        $json = $this->template('<p>{{holder_name}} {{moyenne_generale}}</p>', 422);

        self::assertStringContainsString('moyenne_generale', $json['errors']['html'][0]);
    }

    /** @return list<array{0: string}> */
    public static function dangerousHtml(): array
    {
        return [
            ['<p>{{holder_name}}</p><script>alert(1)</script>'],
            ['<p onclick="x()">{{holder_name}}</p>'],
            ['<img src=x onerror=alert(1)>'],
            ['<a href="javascript:alert(1)">{{holder_name}}</a>'],
            ['<a href="  JaVa&#x09;Script:alert(1)">x</a>'],
            ['<iframe src="https://evil.test"></iframe>'],
            ['<?php echo 1; ?>'],
        ];
    }

    /** @dataProvider dangerousHtml */
    public function testHtmlDangereuxEstRefuse(string $html): void
    {
        $this->template($html, 422);
        self::assertSame(0, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM document_templates WHERE tenant_id = 't1'")->fetchColumn());
    }

    public function testEmissionAvecUnModeleDeLaPlateformeEtDuplicataALaVersionDOrigine(): void
    {
        $original = $this->issueViaApi();
        self::assertSame(1, $original['template_version']);

        // L'établissement publie sa version 1 puis une version 2 : les nouveaux documents l'utilisent.
        $v1 = $this->template('<html><body><p>V-ETAB-1 {{holder_name}} {{number}}</p></body></html>')['data'];
        self::assertSame(1, $v1['version']);
        self::assertNull($v1['validated_at']);
        self::assertSame(200, $this->api('POST', '/document-templates/' . $v1['id'] . '/validate', 'alice', 't1')[0]->getStatusCode());
        $fromV1 = $this->issueViaApi('p2', key: 'cle-emission-0002');
        self::assertSame(1, $fromV1['template_version']);

        $v2 = $this->api('POST', '/document-templates/' . $v1['id'] . '/versions', 'alice', 't1', 'e1', ['html' => '<html><body><p>V-ETAB-2 {{holder_name}} {{number}}</p></body></html>'])[1]['data'];
        self::assertSame(2, $v2['version']);
        $this->api('POST', '/document-templates/' . $v2['id'] . '/validate', 'alice', 't1');
        $fromV2 = $this->issueViaApi('p3', key: 'cle-emission-0003');
        self::assertSame(2, $fromV2['template_version']);

        // Le duplicata du document V1 garde sa version, son numéro, et porte la mention et la date de réimpression.
        $this->clock->set('2026-11-20T10:00:00+01:00');
        [$dup] = $this->api('POST', '/documents/' . $fromV1['id'] . '/duplicate', 'alice', 't1');
        $body = (string) $dup->getBody();

        self::assertSame('application/pdf', $dup->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Duplicata — réimprimé le 20/11/2026', $body);
        self::assertStringContainsString('V-ETAB-1', $body);
        self::assertStringNotContainsString('V-ETAB-2', $body);
        self::assertStringContainsString($fromV1['number'], $body);
        self::assertSame($fromV1['number'], $this->api('GET', '/documents/' . $fromV1['id'], 'alice', 't1')[1]['data']['number']);

        // Le premier document (modèle de la plateforme) n'est pas touché.
        $content = (string) $this->connections()->pdo()->query("SELECT content FROM documents WHERE id = '" . $original['id'] . "'")->fetchColumn();
        self::assertStringContainsString('Attestation de scolarité', $content);
    }

    public function testUneVersionValideeNeSeModifieNiNeSeSupprimePlus(): void
    {
        $t = $this->template('<p>{{holder_name}}</p>')['data'];
        self::assertSame(200, $this->api('PATCH', '/document-templates/' . $t['id'], 'alice', 't1', 'e1', ['html' => '<p>{{number}}</p>'])[0]->getStatusCode());
        $this->api('POST', '/document-templates/' . $t['id'] . '/validate', 'alice', 't1');

        self::assertSame(409, $this->api('PATCH', '/document-templates/' . $t['id'], 'alice', 't1', 'e1', ['html' => '<p>x</p>'])[0]->getStatusCode());
        self::assertSame(409, $this->api('DELETE', '/document-templates/' . $t['id'], 'alice', 't1')[0]->getStatusCode());

        $draft = $this->api('POST', '/document-templates/' . $t['id'] . '/versions', 'alice', 't1', 'e1', [])[1]['data'];
        self::assertSame(200, $this->api('DELETE', '/document-templates/' . $draft['id'], 'alice', 't1')[0]->getStatusCode());
    }

    public function testLesModelesDeLaPlateformeSontEnLectureSeule(): void
    {
        $list = $this->api('GET', '/document-templates', 'alice', 't1')[1]['data'];
        $system = array_values(array_filter($list, static fn (array $t): bool => $t['system'] === true));
        self::assertCount(2, $system);

        self::assertSame(403, $this->api('PATCH', '/document-templates/' . $system[0]['id'], 'alice', 't1', 'e1', ['html' => '<p>x</p>'])[0]->getStatusCode());
        self::assertSame(403, $this->api('PATCH', '/document-types/01JZ0000000000000000000001', 'alice', 't1', 'e1', ['label' => 'x'])[0]->getStatusCode());
    }

    public function testTypePersonnaliseGabaritDeNumeroConfigurable(): void
    {
        [$created, $json] = $this->api('POST', '/document-types', 'alice', 't1', 'e1', [
            'code' => 'releve_notes', 'label' => 'Relevé de notes', 'category' => 'other', 'type_letter' => 'R',
            'number_template' => 'REL-EC-{AAAA}-{N6}', 'variables' => ['moyenne'],
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame(['moyenne'], $json['data']['variables']);
        self::assertSame(422, $this->api('POST', '/document-types', 'alice', 't1', 'e1', ['code' => 'x_y', 'label' => 'x', 'category' => 'other', 'type_letter' => 'X', 'number_template' => 'SANS-COMPTEUR'])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/document-types', 'alice', 't1', 'e1', ['code' => 'x_z', 'label' => 'x', 'category' => 'other', 'type_letter' => 'X', 'variables' => ['number']])[0]->getStatusCode());
        self::assertSame(409, $this->api('POST', '/document-types', 'alice', 't1', 'e1', ['code' => 'releve_notes', 'label' => 'x', 'category' => 'other', 'type_letter' => 'R'])[0]->getStatusCode());

        $this->api('POST', '/document-templates', 'alice', 't1', 'e1', ['type_code' => 'releve_notes', 'locale' => 'fr', 'html' => '<p>{{holder_name}} : {{moyenne}}</p>']);
        $tpl = $this->api('GET', '/document-templates?filter[type_code]=releve_notes', 'alice', 't1')[1]['data'][0];
        $this->api('POST', '/document-templates/' . $tpl['id'] . '/validate', 'alice', 't1');

        $doc = $this->issuer()->issue('t1', 'e1', 'releve_notes', 'p1', ['moyenne' => 14.5], 'alice');
        self::assertSame('REL-EC-2026-000001', $doc->number);
    }
}
