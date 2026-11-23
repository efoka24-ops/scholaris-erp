<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Modules\Socle\Documents\Application\VerificationService;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Tests\Support\Billing\ArraySettings;
use Tests\Support\Documents\DocumentsTestCase;

/** SS12 : POST /documents/{id}/rectify, état « remplacé » à la vérification par QR, nouveau numéro paramétrable. */
final class RectifyRouteTest extends DocumentsTestCase
{
    private ArraySettings $settings;

    protected function setUp(): void
    {
        $this->settings = new ArraySettings();
        $this->bootDocuments([SettingsReader::class => $this->settings]);
        $this->permissions->grant('alice', 'documents.rectify');
    }

    public function testRectificatifSousUnNouveauNumeroAvecMentionEtNumeroDOrigine(): void
    {
        $this->settings->values['documents.rectify_numbering'] = 'new_number';
        $this->settings->values['documents.verification.detailed_status'] = true;
        $old = $this->issueViaApi('p1', data: ['academic_year' => '2026-2027', 'class_name' => '3eA']);

        [$response, $json] = $this->api('POST', '/documents/' . $old['id'] . '/rectify', 'alice', 't1', 'e1', [
            'reason' => 'Erreur de classe', 'data' => ['academic_year' => '2026-2027', 'class_name' => '3eB'],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $new = $json['data'];
        self::assertNotSame($old['number'], $new['number'], 'Nouveau numéro.');
        self::assertSame(['id' => $old['id'], 'number' => $old['number']], $new['rectifies']);
        self::assertSame('reissue', $new['issued_via']);

        $html = $this->kernel->container()->get(\Scholaris\Modules\Socle\Documents\Application\DocumentService::class)->content('t1', 'e1', (string) $new['id']);
        self::assertStringContainsString('Rectificatif du document n° ' . $old['number'], $html, 'Mention et numéro d\'origine.');
        self::assertStringContainsString('3eB', $html);

        $oldPage = $this->json($this->publicVerify((string) $old['verification_token']))['data'];
        self::assertSame('revoked', $oldPage['status']);
        self::assertSame('replaced', $oldPage['state'], 'État « remplacé » visible au QR.');
        self::assertSame($new['number'], $oldPage['replaced_by_number']);
        self::assertSame(VerificationService::RECTIFIED_RENUMBERED_REASON, $oldPage['revoke_reason']);

        $newPage = $this->json($this->publicVerify((string) $new['verification_token']))['data'];
        self::assertSame('valid', $newPage['state']);
        self::assertSame($old['number'], $newPage['rectifies_number']);
        self::assertStringNotContainsString('Erreur de classe', json_encode([$oldPage, $newPage], JSON_THROW_ON_ERROR), 'RG-DOC-2 : motif non publié.');
    }

    public function testParDefautLeNumeroEstConserveCommeAvant(): void
    {
        $old = $this->issueViaApi();

        [$response, $json] = $this->api('POST', '/documents/' . $old['id'] . '/rectify', 'alice', 't1', 'e1', ['reason' => 'Coquille']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($old['number'], $json['data']['number']);
        $page = $this->json($this->publicVerify((string) $json['data']['verification_token']))['data'];
        self::assertArrayNotHasKey('rectifies_number', $page);
        self::assertArrayNotHasKey('state', $page, 'Réponse du socle inchangée sans detailed_status.');
    }

    public function testDroitDedieMotifObligatoireEtDocumentDejaRemplace(): void
    {
        $doc = $this->issueViaApi();

        [$forbidden] = $this->api('POST', '/documents/' . $doc['id'] . '/rectify', 'bob', 't2', 'e3', ['reason' => 'Motif']);
        self::assertContains($forbidden->getStatusCode(), [403, 404]);
        $this->permissions->grant('carol', 'documents.read', 'documents.revoke');
        $this->access->grant('carol', 'e1');
        [$noRight] = $this->api('POST', '/documents/' . $doc['id'] . '/rectify', 'carol', 't1', 'e1', ['reason' => 'Motif']);
        self::assertSame(403, $noRight->getStatusCode(), 'documents.revoke ne suffit pas : droit dédié.');

        [$noReason] = $this->api('POST', '/documents/' . $doc['id'] . '/rectify', 'alice', 't1', 'e1', []);
        self::assertSame(422, $noReason->getStatusCode());

        [$first] = $this->api('POST', '/documents/' . $doc['id'] . '/rectify', 'alice', 't1', 'e1', ['reason' => 'Premier']);
        self::assertSame(201, $first->getStatusCode());
        [$again] = $this->api('POST', '/documents/' . $doc['id'] . '/rectify', 'alice', 't1', 'e1', ['reason' => 'Second']);
        self::assertSame(409, $again->getStatusCode());
    }
}
