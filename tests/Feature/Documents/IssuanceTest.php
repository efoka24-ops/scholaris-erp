<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Tests\Support\Documents\DocumentsTestCase;

final class IssuanceTest extends DocumentsTestCase
{
    private const DATA = ['academic_year' => '2026-2027', 'class_name' => '3eA'];

    protected function setUp(): void
    {
        $this->bootDocuments();
    }

    public function testLeContratDocumentIssuerEstRespecte(): void
    {
        self::assertInstanceOf(DocumentIssuer::class, $this->issuer());

        $doc = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice');

        self::assertSame(IssuedDocument::VALID, $doc->status);
        self::assertSame('attestation_simple', $doc->type);
        self::assertSame('p1', $doc->subjectPersonId);
        self::assertSame('CLP-26-A-000001', $doc->number);
        self::assertNotSame('', $doc->id);

        $this->issuer()->revoke('t1', $doc->id, 'Erreur de saisie', 'alice');
        self::assertSame('revoked', $this->api('GET', '/documents/' . $doc->id, 'alice', 't1')[1]['data']['status']);
    }

    public function testNumerotationContinueSansTrouParEtablissementTypeEtAnnee(): void
    {
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice')->number;
        }
        self::assertSame(['CLP-26-A-000001', 'CLP-26-A-000002', 'CLP-26-A-000003'], $numbers);

        // Un autre établissement a sa propre suite ; une nouvelle année repart de 1.
        self::assertSame('LB-26-A-000001', $this->issuer()->issue('t1', 'e2', 'attestation_simple', 'p1', self::DATA, 'alice')->number);
        $this->clock->set('2027-01-05T09:00:00+01:00');
        self::assertSame('CLP-27-A-000001', $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice')->number);
    }

    public function testUnEchecEnCoursDEmissionNeConsommePasLeNumero(): void
    {
        self::assertSame('CLP-26-A-000001', $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice')->number);

        $this->qr->fail = true;
        try {
            $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p2', self::DATA, 'alice');
            self::fail('L\'émission aurait dû échouer.');
        } catch (\RuntimeException) {
            // attendu : la transaction est annulée
        }
        $this->qr->fail = false;

        self::assertSame('CLP-26-A-000002', $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p2', self::DATA, 'alice')->number);
        $count = (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM documents')->fetchColumn();
        self::assertSame(2, $count);
    }

    public function testLesJetonsSontUniquesNonSequentielsEtDe128Bits(): void
    {
        $tokens = [];
        for ($i = 0; $i < 20; $i++) {
            $tokens[] = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice')->verificationToken;
        }
        self::assertCount(20, array_unique($tokens));
        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $token);
        }
        $sorted = $tokens;
        sort($sorted);
        self::assertNotSame($sorted, $tokens, 'Les jetons ne doivent pas croître avec le numéro.');
    }

    public function testVariableNonDeclareeEtTypeInconnuSontRefuses(): void
    {
        foreach ([
            ['attestation_simple', ['academic_year' => '2026', 'moyenne' => '15']],
            ['type_inconnu', self::DATA],
        ] as [$type, $data]) {
            try {
                $this->issuer()->issue('t1', 'e1', $type, 'p1', $data, 'alice');
                self::fail('Un 422 était attendu.');
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM documents')->fetchColumn());
    }

    public function testLeContenuEstEchappeEtL_empreinteCorrespond(): void
    {
        $doc = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', ['class_name' => '<script>alert(1)</script>', 'academic_year' => '2026'], 'alice');

        $row = $this->connections()->pdo()->query("SELECT content, fingerprint FROM documents WHERE id = '" . $doc->id . "'")->fetch();
        self::assertStringNotContainsString('<script>', $row['content']);
        self::assertStringContainsString('&lt;script&gt;', $row['content']);
        self::assertStringContainsString('Collège La Palmeraie', $row['content']);
        self::assertStringContainsString('/public/verify/' . $doc->verificationToken, $row['content']);
        self::assertSame(hash('sha256', $row['content']), $row['fingerprint']);
    }

    public function testEmissionHttpExigeUneCleDIdempotenceEtLaRejoue(): void
    {
        $body = ['type' => 'attestation_simple', 'subject_person_id' => 'p1', 'data' => self::DATA];

        [$sans] = $this->api('POST', '/documents', 'alice', 't1', 'e1', $body);
        self::assertSame(400, $sans->getStatusCode());

        [$first, $a] = $this->api('POST', '/documents', 'alice', 't1', 'e1', $body, ['Idempotency-Key' => 'cle-doc-00000001']);
        [$replay, $b] = $this->api('POST', '/documents', 'alice', 't1', 'e1', $body, ['Idempotency-Key' => 'cle-doc-00000001']);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame('true', $replay->getHeaderLine('Idempotent-Replayed'));
        self::assertSame($a['data']['number'], $b['data']['number']);
        self::assertSame(1, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM documents')->fetchColumn());
    }

    public function testPermissionsRefusParDefaut(): void
    {
        $this->access->grant('carole', 'e1');
        $body = ['type' => 'attestation_simple', 'subject_person_id' => 'p1', 'data' => self::DATA];

        self::assertSame(403, $this->api('POST', '/documents', 'carole', 't1', 'e1', $body, ['Idempotency-Key' => 'cle-doc-00000002'])[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/documents', 'carole', 't1')[0]->getStatusCode());

        $this->permissions->grant('carole', 'documents.read');
        self::assertSame(200, $this->api('GET', '/documents', 'carole', 't1')[0]->getStatusCode());
        self::assertSame(403, $this->api('POST', '/documents', 'carole', 't1', 'e1', $body, ['Idempotency-Key' => 'cle-doc-00000003'])[0]->getStatusCode());
    }

    public function testApercuPdfEtSignatureSimple(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgo=';
        [$put, $seal] = $this->api('PUT', '/document-seal', 'alice', 't1', 'e1', ['signer_name' => 'Mme Ateba', 'signer_title' => 'Directrice', 'signature_image' => $png, 'stamp_image' => $png]);
        self::assertSame(200, $put->getStatusCode());
        self::assertTrue($seal['data']['has_signature']);
        self::assertArrayNotHasKey('signature_image', $seal['data']);
        self::assertSame(422, $this->api('PUT', '/document-seal', 'alice', 't1', 'e1', ['signer_name' => 'X', 'signature_image' => 'javascript:alert(1)'])[0]->getStatusCode());

        $doc = $this->issueViaApi();
        self::assertNotNull($doc['signed_at']);

        [$pdf] = $this->api('GET', '/documents/' . $doc['id'] . '/preview', 'alice', 't1');
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());
        self::assertStringContainsString('Mme Ateba', (string) $this->connections()->pdo()->query("SELECT content FROM documents WHERE id = '" . $doc['id'] . "'")->fetchColumn());
    }
}
