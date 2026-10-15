<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Tests\Support\Documents\DocumentsTestCase;

final class PublicVerificationTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        $this->bootDocuments();
    }

    public function testDocumentValideSansAuthentificationEtTitulaireMasque(): void
    {
        $doc = $this->issueViaApi();

        $response = $this->publicVerify($doc['verification_token']);
        $data = $this->json($response)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('valid', $data['status']);
        self::assertSame('F••• Nadia', $data['holder']);
        self::assertSame('Collège La Palmeraie', $data['establishment']);
        self::assertSame('Attestation de scolarité', $data['type_label']);
        self::assertSame($doc['number'], $data['number']);
        self::assertSame(2026, $data['year']);
        self::assertSame('2026-10-08', $data['issued_on']);
        self::assertSame(substr($doc['fingerprint'], 0, 4) . '…' . substr($doc['fingerprint'], -4), $data['fingerprint']);

        // Aucune donnée en trop : ni nom complet, ni variables du document, ni identifiants.
        $raw = (string) $response->getBody();
        foreach (['Fotso', 'p1', '3eA', '2026-2027', 'alice', 'subject_person_id', 'tenant', 'content', 'data_json'] as $leak) {
            self::assertStringNotContainsString($leak, $raw, 'Fuite : ' . $leak);
        }
        self::assertSame(['status', 'type', 'type_label', 'establishment', 'holder', 'year', 'number', 'issued_on', 'fingerprint'], array_keys($data));
    }

    public function testDocumentRevoqueAfficheLeMotifEtLeRemplacement(): void
    {
        $old = $this->issueViaApi('p1', key: 'cle-emission-0001');
        $new = $this->issueViaApi('p1', key: 'cle-emission-0002');
        [$revoke] = $this->api('POST', '/documents/' . $old['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Mauvaise classe', 'replaced_by' => $new['id']]);
        self::assertSame(200, $revoke->getStatusCode());

        $response = $this->publicVerify($old['verification_token']);
        $data = $this->json($response)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('revoked', $data['status']);
        self::assertSame('Mauvaise classe', $data['revoke_reason']);
        self::assertSame($new['number'], $data['replaced_by_number']);
        self::assertSame('F••• Nadia', $data['holder']);
        self::assertSame('valid', $this->json($this->publicVerify($new['verification_token']))['data']['status']);
    }

    public function testJetonInconnuDonne404AvecLeMessageDeLaMaquette(): void
    {
        foreach (['AAAAAAAAAAAAAAAAAAAAAA', 'court', str_repeat('x', 60)] as $token) {
            $response = $this->publicVerify($token);
            $body = $this->json($response);

            self::assertSame(404, $response->getStatusCode());
            self::assertSame('Ce document n\'a pas été émis par SCHOLARIS.', $body['detail']);
            self::assertArrayNotHasKey('data', $body);
        }
    }

    public function testChaqueConsultationEstJournaliseeAvecUneIpHachee(): void
    {
        $doc = $this->issueViaApi();
        $this->publicVerify($doc['verification_token']);
        $this->publicVerify('AAAAAAAAAAAAAAAAAAAAAA');

        $rows = $this->connections()->pdo()->query('SELECT tenant_id, token, outcome, ip_hash FROM verification_log ORDER BY consulted_at, id')->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame(['valid', 'unknown'], array_column($rows, 'outcome'));
        self::assertSame('t1', $rows[0]['tenant_id']);
        foreach ($rows as $row) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['ip_hash']);
        }
    }

    public function testLaRouteEstPubliqueMaisDeclareeAuNoyauPourLaLimiteDeDebit(): void
    {
        $route = array_values(array_filter($this->kernel->routes()->all(), static fn (array $r): bool => $r['path'] === '/api/v1/public/verify/{token}'));

        self::assertCount(1, $route);
        self::assertFalse($route[0]['options']['auth']);
        self::assertFalse($route[0]['options']['tenant']);
    }
}
