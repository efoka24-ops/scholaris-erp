<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Scholaris\Core\Http\HttpException;
use Tests\Support\Documents\DocumentsTestCase;

/** Aucune fuite entre clients ni entre établissements pour le module Documents. */
final class DocumentsIsolationTest extends DocumentsTestCase
{
    /** @var array<string, mixed> */
    private array $doc;
    private string $batchId;
    private string $requestId;
    private string $templateId;

    protected function setUp(): void
    {
        $this->bootDocuments();
        $this->doc = $this->issueViaApi();
        $this->batchId = $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => [['subject_person_id' => 'p2', 'data' => []]]])[1]['data']['id'];
        $this->requestId = $this->api('POST', '/document-requests', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'subject_person_id' => 'p2'])[1]['data']['id'];
        $this->templateId = $this->api('POST', '/document-templates', 'alice', 't1', 'e1', ['type_code' => 'attestation_simple', 'locale' => 'fr', 'html' => '<p>{{holder_name}}</p>'])[1]['data']['id'];
    }

    public function testIdentifiantsDUnAutreClientDonnent404(): void
    {
        $id = $this->doc['id'];
        foreach ([
            ['GET', '/documents/' . $id],
            ['GET', '/documents/' . $id . '/preview'],
            ['POST', '/documents/' . $id . '/duplicate'],
            ['POST', '/documents/' . $id . '/revoke', ['reason' => 'piratage']],
            ['GET', '/document-batches/' . $this->batchId],
            ['GET', '/document-batches/' . $this->batchId . '/excluded'],
            ['POST', '/document-batches/' . $this->batchId . '/process'],
            ['POST', '/document-requests/' . $this->requestId . '/fulfill', []],
            ['POST', '/document-requests/' . $this->requestId . '/reject'],
            ['GET', '/document-templates/' . $this->templateId],
            ['PATCH', '/document-templates/' . $this->templateId, ['html' => '<p>x</p>']],
            ['POST', '/document-templates/' . $this->templateId . '/validate'],
            ['POST', '/document-templates/' . $this->templateId . '/versions', []],
            ['DELETE', '/document-templates/' . $this->templateId],
        ] as $call) {
            $code = $this->api($call[0], $call[1], 'bob', 't2', 'e3', $call[2] ?? null)[0]->getStatusCode();
            self::assertSame(404, $code, $call[0] . ' ' . $call[1]);
        }
        self::assertSame('valid', $this->api('GET', '/documents/' . $id, 'alice', 't1')[1]['data']['status']);
    }

    public function testLesListesNeContiennentQueLesDonneesDuClientEtDeLEtablissement(): void
    {
        foreach (['/documents', '/document-batches', '/document-requests'] as $path) {
            self::assertSame([], $this->api('GET', $path, 'bob', 't2', 'e3')[1]['data'], $path . ' chez l\'autre client');
            self::assertSame([], $this->api('GET', $path, 'alice', 't1', 'e2')[1]['data'], $path . ' dans l\'autre établissement');
            self::assertCount(1, $this->api('GET', $path, 'alice', 't1', 'e1')[1]['data'], $path . ' chez le propriétaire');
        }
        $templates = $this->api('GET', '/document-templates', 'bob', 't2', 'e3')[1]['data'];
        self::assertSame([], array_values(array_filter($templates, static fn (array $t): bool => $t['system'] === false)));
        self::assertStringNotContainsString('Fotso', (string) $this->api('GET', '/documents/export', 'bob', 't2', 'e3')[0]->getBody());
        self::assertSame(404, $this->api('GET', '/documents/' . $this->doc['id'], 'alice', 't1', 'e2')[0]->getStatusCode());
    }

    public function testEnTeteVersLEtablissementDUnAutreClient(): void
    {
        self::assertSame(403, $this->api('GET', '/documents', 'bob', 't2', 'e1')[0]->getStatusCode());
        $this->access->grant('bob', 'e1');
        self::assertSame([], $this->api('GET', '/documents', 'bob', 't2', 'e1')[1]['data'], 'Même avec l\'en-tête, aucune donnée du client t1.');
        self::assertSame(404, $this->api('GET', '/documents/' . $this->doc['id'], 'bob', 't2', 'e1')[0]->getStatusCode());
        self::assertSame(404, $this->api('POST', '/documents', 'bob', 't2', 'e1', ['type' => 'attestation_simple', 'subject_person_id' => 'p9', 'data' => []], ['Idempotency-Key' => 'cle-isolation-001'])[0]->getStatusCode());
    }

    public function testEmissionPourUnePersonneOuUnEtablissementDUnAutreClient(): void
    {
        foreach ([['t1', 'e1', 'p9'], ['t2', 'e1', 'p9'], ['t2', 'e3', 'p1']] as [$tenant, $establishment, $person]) {
            try {
                $this->issuer()->issue($tenant, $establishment, 'attestation_simple', $person, [], 'alice');
                self::fail('Émission interclients acceptée.');
            } catch (HttpException $e) {
                self::assertContains($e->status, [404, 422]);
            }
        }
        self::assertSame(1, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM documents')->fetchColumn());
        try {
            $this->issuer()->revoke('t2', $this->doc['id'], 'piratage', 'bob');
            self::fail('Révocation interclients acceptée.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testVerificationPubliqueNeRevelePasLeClientDuJeton(): void
    {
        $body = (string) $this->publicVerify($this->doc['verification_token'])->getBody();

        self::assertStringNotContainsString('"t1"', $body);
        // Valeur exacte entre guillemets : une empreinte hexadécimale aléatoire peut contenir « e1 » par hasard.
        self::assertStringNotContainsString('"e1"', $body);
        self::assertStringNotContainsString('tenant_id', $body);
        self::assertStringNotContainsString('establishment_id', $body);
    }
}
