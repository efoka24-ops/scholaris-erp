<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Contracts\ExtendedDocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssueOptions;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Tests\Support\Billing\ArraySettings;
use Tests\Support\Documents\DocumentsTestCase;
use Tests\Support\Documents\TitleGuard;

/**
 * Extensions IPES de Documents : S12 (blocage pour impayé à l'émission unitaire), S16 (jeton {ECOLE}), S17 (signataire par type
 * et par école), S18 (gardes d'émission enregistrées), S19 (état « remplacé » sur la page publique). Désactivées par défaut.
 */
final class IpesDocumentsExtensionsTest extends DocumentsTestCase
{
    private const DATA = ['academic_year' => '2026-2027', 'class_name' => 'L1 CF'];

    private ArraySettings $settings;

    protected function setUp(): void
    {
        TitleGuard::$seen = [];
        $this->settings = new ArraySettings();
        $this->bootDocuments([
            SettingsReader::class => $this->settings,
            'documents.issuance_guards.higher' => [TitleGuard::class],
        ]);
    }

    private function extended(): ExtendedDocumentIssuer
    {
        return $this->kernel->container()->get(ExtendedDocumentIssuer::class);
    }

    /** @param array<string, mixed> $extra */
    private function redeclare(array $extra): void
    {
        [$response] = $this->api('POST', '/document-types', 'alice', 't1', 'e1', [
            'code' => 'attestation_simple', 'label' => 'Relevé de notes', 'category' => 'certificate', 'type_letter' => 'R',
            'variables' => ['academic_year', 'class_name'],
        ] + $extra);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    private function expectStatus(int $status, callable $call): void
    {
        try {
            $call();
            self::fail('Refus ' . $status . ' attendu.');
        } catch (HttpException $e) {
            self::assertSame($status, $e->status);
        }
    }

    public function testNumeroParEcoleRelEc2026000315(): void
    {
        $this->redeclare(['number_template' => 'REL-{ECOLE}-{AAAA}-{N6}']);
        $this->connections()->pdo()->exec("INSERT INTO number_sequences (tenant_id, establishment_id, kind, year, current_value) VALUES ('t1', 'e1', 'doc:attestation_simple@EC', 2026, 314)");

        $doc = $this->extended()->issueWithOptions('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice', 'fr', new IssueOptions('EC', 'ou-ec'));
        self::assertSame('REL-EC-2026-000315', $doc->number);
        self::assertMatchesRegularExpression('/^[A-Z]{3}-[A-Z]{2,}-\d{4}-\d{6}$/', $doc->number);

        // Chaque école a sa propre suite ; le code d'école est obligatoire pour ce gabarit.
        self::assertSame('REL-ESG-2026-000001', $this->extended()->issueWithOptions('t1', 'e1', 'attestation_simple', 'p2', self::DATA, 'alice', 'fr', new IssueOptions('ESG'))->number);
        self::assertSame('REL-EC-2026-000316', $this->extended()->issueWithOptions('t1', 'e1', 'attestation_simple', 'p2', self::DATA, 'alice', 'fr', new IssueOptions('EC'))->number);
        $this->expectStatus(422, fn () => $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice'));
        $this->expectStatus(422, fn () => $this->extended()->issueWithOptions('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice', 'fr', new IssueOptions('ec')));

        [$response, $json] = $this->api('GET', '/documents/' . $doc->id, 'alice', 't1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('REL-EC-2026-000315', $json['data']['number']);
    }

    public function testSignataireParTypeEtParEcoleAvecRepliSurLeCachet(): void
    {
        $this->api('PUT', '/document-seal', 'alice', 't1', 'e1', ['signer_name' => 'Cachet Établissement']);
        $this->redeclare(['signer_role' => 'directeur_ecole']);

        [$type] = $this->api('GET', '/document-types', 'alice', 't1');
        self::assertSame(200, $type->getStatusCode());

        // Aucun signataire pour ce rôle : repli sur le cachet de l'établissement.
        $fallback = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice');
        self::assertStringContainsString('Cachet Établissement', $this->content($fallback->id));

        [$put, $json] = $this->api('PUT', '/document-seal', 'alice', 't1', 'e1', ['signer_name' => 'Pr Ngo Bassa', 'signer_title' => 'Directeur de l\'École de commerce', 'signer_role' => 'directeur_ecole', 'org_unit_id' => 'ou-ec']);
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertSame(['signer_role' => 'directeur_ecole', 'org_unit_id' => 'ou-ec', 'configured' => true], array_slice($json['data'], 0, 3));
        $this->api('PUT', '/document-seal', 'alice', 't1', 'e1', ['signer_name' => 'M. Directeur Général', 'signer_role' => 'directeur_general']);
        $this->api('PUT', '/document-seal', 'alice', 't1', 'e1', ['signer_name' => 'Dr Établissement', 'signer_role' => 'directeur_ecole']);

        [$get, $shown] = $this->api('GET', '/document-seal?signer_role=directeur_ecole&org_unit_id=ou-ec', 'alice', 't1');
        self::assertSame(200, $get->getStatusCode());
        self::assertSame('Pr Ngo Bassa', $shown['data']['signer_name']);
        [, $seal] = $this->api('GET', '/document-seal', 'alice', 't1');
        self::assertSame('Cachet Établissement', $seal['data']['signer_name']);
        [$orphan] = $this->api('PUT', '/document-seal', 'alice', 't1', 'e1', ['signer_name' => 'X', 'org_unit_id' => 'ou-ec']);
        self::assertSame(422, $orphan->getStatusCode());

        $school = $this->extended()->issueWithOptions('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice', 'fr', new IssueOptions(null, 'ou-ec'));
        self::assertStringContainsString('Pr Ngo Bassa', $this->content($school->id));
        $other = $this->extended()->issueWithOptions('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice', 'fr', new IssueOptions(null, 'ou-autre'));
        self::assertStringContainsString('Dr Établissement', $this->content($other->id));
        $dg = $this->extended()->issueWithOptions('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice', 'fr', new IssueOptions(null, 'ou-ec', 'directeur_general'));
        self::assertStringContainsString('M. Directeur Général', $this->content($dg->id));

        $row = $this->connections()->pdo()->query("SELECT org_unit_id, signer_role FROM documents WHERE id = '" . $school->id . "'")->fetch();
        self::assertSame(['ou-ec', 'directeur_ecole'], [$row['org_unit_id'], $row['signer_role']]);
    }

    public function testBlocageUnitairePourImpayeSeulementSiParametreOuDemande(): void
    {
        $this->redeclare(['blockable' => true]);
        $this->debtors->block('p2');

        // Défaut du socle : l'émission unitaire n'est pas bloquée.
        self::assertSame('valid', $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p2', self::DATA, 'alice')->status);
        $this->expectStatus(422, fn () => $this->extended()->issueWithOptions('t1', 'e1', 'attestation_simple', 'p2', self::DATA, 'alice', 'fr', new IssueOptions(enforceFinancialBlock: true)));

        $this->settings->values['documents.block_single_issuance'] = true;
        [$response, $json] = $this->api('POST', '/documents', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'subject_person_id' => 'p2', 'data' => self::DATA], ['Idempotency-Key' => 'cle-blocage-0001']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('impayée', $json['errors']['subject_person_id'][0]);
        self::assertSame('valid', $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice')->status);

        // Un type non bloquable (certificat de scolarité, reçu...) n'est jamais bloqué.
        $this->api('PATCH', '/document-types/' . $this->typeId(), 'alice', 't1', 'e1', ['blockable' => false]);
        self::assertSame('valid', $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p2', self::DATA, 'alice')->status);
    }

    public function testGardeEnregistreeRefuseAvantEmissionSansConsommerDeNumero(): void
    {
        $this->expectStatus(422, fn () => $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', ['academic_year' => '2026-2027', 'class_name' => 'BTS 2'], 'alice'));
        [$response, $json] = $this->api('POST', '/documents', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'subject_person_id' => 'p1', 'data' => ['academic_year' => '2026-2027', 'class_name' => 'HND BTS']], ['Idempotency-Key' => 'cle-garde-0001']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('BTS', $json['errors']['issuance'][0]);

        self::assertSame('CLP-26-A-000001', $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice')->number);
        self::assertContains('api:attestation_simple:-', TitleGuard::$seen);
    }

    public function testEtatRemplaceSurLaPagePubliqueQuandLeParametreEstActif(): void
    {
        $first = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice');
        $second = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice');
        $third = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', self::DATA, 'alice');
        $this->api('POST', '/documents/' . $first->id . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Erreur de note', 'replaced_by' => $second->id]);
        $this->api('POST', '/documents/' . $third->id . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Émis par erreur']);

        $data = (array) json_decode((string) $this->publicVerify($first->verificationToken)->getBody(), true)['data'];
        self::assertArrayNotHasKey('state', $data);
        self::assertSame('revoked', $data['status']);

        $this->settings->values['documents.verification.detailed_status'] = true;
        $states = array_map(
            fn (string $token): string => (string) json_decode((string) $this->publicVerify($token)->getBody(), true)['data']['state'],
            [$first->verificationToken, $second->verificationToken, $third->verificationToken],
        );
        self::assertSame(['replaced', 'valid', 'cancelled'], $states);
        $replaced = (array) json_decode((string) $this->publicVerify($first->verificationToken)->getBody(), true)['data'];
        self::assertSame('revoked', $replaced['status']);
        self::assertSame($second->number, $replaced['replaced_by_number']);
        self::assertArrayNotHasKey('data', $replaced);
    }

    private function content(string $id): string
    {
        return (string) $this->connections()->pdo()->query("SELECT content FROM documents WHERE id = '" . $id . "'")->fetchColumn();
    }

    private function typeId(): string
    {
        return (string) $this->connections()->pdo()->query("SELECT id FROM document_types WHERE tenant_id = 't1' AND code = 'attestation_simple'")->fetchColumn();
    }
}
