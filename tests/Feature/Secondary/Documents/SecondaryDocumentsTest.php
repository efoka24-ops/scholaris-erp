<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Documents;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Documents\Application\DocumentTypeCatalogue;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Secondary\Portals\PortalWorld;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T833 : documents du lot E1 (DOC-01 à 06, 27 à 29) par le socle Documents : une émission par type, numéro unique, QR selon le CDC,
 * contenu figé FR/EN, aucun montant sur un document scolaire (RG-DOC-4), exeat seulement après clôture, périmètre et isolation.
 * Données fictives.
 */
final class SecondaryDocumentsTest extends SecondaryTestCase
{
    private const SCOLARITE = 'scolarite-a';
    private const BURSAR = 'intendant-a';

    private InMemoryEnrollments $enrollments;
    private InMemoryPeople $people;
    private PortalWorld $world;
    private string $school;
    private string $classA;
    private string $classB;

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->people = new InMemoryPeople();
        $this->world = new PortalWorld();
        $this->bootSecondary([
            EnrollmentDirectory::class => $this->enrollments,
            PersonDirectory::class => $this->people,
            InvoiceIssuer::class => $this->world,
            DebtorDirectory::class => $this->world,
            UserPersonLink::class => $this->world,
            'secondary.student_facts.test' => [$this->world],
        ]);
        $this->school = $this->factory->opened(self::TA, 'leg');
        $writer = $this->service(StructureWriter::class);
        $level = $writer->createStructure(self::TA, $this->school, new StructureDraft(StructureDraft::LEVEL, '6e'));
        $this->classA = $writer->createStructure(self::TA, $this->school, new StructureDraft(StructureDraft::SCHOOL_CLASS, '6e A', null, $level));
        $this->classB = $writer->createStructure(self::TA, $this->school, new StructureDraft(StructureDraft::SCHOOL_CLASS, '6e B', null, $level));

        $this->people->add(self::TA, 'eleve-1', 'Aïcha', 'Exemple');
        $this->people->add(self::TA, 'eleve-2', 'Brice', 'Fictif');
        $this->people->add(self::TA, 'parent-1', 'Mariam', 'Exemple', '+237600000001');
        $this->people->add(self::TA, 'agent-1', 'Paul', 'Scolarite');
        $this->people->link(self::TA, 'parent-1', 'eleve-1');
        $this->enrollments->add(self::TA, 'ins-1', 'eleve-1', $this->school, $this->classA, 'ay-2026');
        $this->enrollments->add(self::TA, 'ins-2', 'eleve-2', $this->school, $this->classA, 'ay-2026');
        $this->world->link(self::TA, self::SCOLARITE, 'agent-1');
        $this->world->nationalIds['eleve-1'] = 'MAT-FICTIF-001';
        $this->grant(self::TA, self::SCOLARITE, $this->school, ['secondary.documents.issue', 'secondary.enrol.manage']);
        $this->grant(self::TA, self::BURSAR, $this->school, ['secondary.billing.manage']);
    }

    public function testLeCatalogueReprendLesDocumentsE1AvecLeQrDuCdc(): void
    {
        $items = $this->ok('GET', '/secondary/documents/kinds', null, self::SCOLARITE, self::TA, $this->school)['items'];
        $qr = array_column($items, 'qr', 'doc');
        self::assertSame(['DOC-01', 'DOC-02', 'DOC-03', 'DOC-04', 'DOC-05', 'DOC-06', 'DOC-27', 'DOC-28', 'DOC-29'], array_keys($qr));
        self::assertSame([false, false, true, true, true, false, true, false, true], array_values($qr));
        $byKind = array_column($items, null, 'kind');
        self::assertFalse($byKind['receipt']['direct'], 'Le reçu DOC-27 est émis par le socle Billing.');
        self::assertFalse($byKind['preregistration']['direct'], 'DOC-01 : par le contrat, depuis le parcours de préinscription.');
        self::assertTrue($byKind['school_certificate']['can_issue']);
        self::assertFalse($byKind['account_statement']['can_issue'], 'La scolarité n\'émet pas les documents financiers.');
        self::assertTrue($byKind['school_certificate']['available']);
    }

    public function testLesModelesDuSocleExistentEnFrancaisEtEnAnglaisSansPointVirgule(): void
    {
        $catalogue = $this->service(DocumentTypeCatalogue::class);
        foreach ($catalogue->all() as $type) {
            if ($type->socleCode === null) {
                continue;
            }
            $row = $this->rows('SELECT qr_enabled, variables FROM document_types WHERE tenant_id = ? AND code = ?', ['*', (string) $type->socleCode])[0] ?? null;
            self::assertIsArray($row, $type->doc);
            self::assertSame($type->qr ? 1 : 0, (int) $row['qr_enabled'], $type->doc . ' : QR selon le CDC');
            foreach (['fr', 'en'] as $locale) {
                $html = $this->rows('SELECT html FROM document_templates WHERE tenant_id = ? AND type_code = ? AND locale = ?', ['*', (string) $type->socleCode, $locale])[0]['html'] ?? null;
                self::assertIsString($html, $type->doc . ' ' . $locale);
                self::assertStringNotContainsString(';', $html);
                TemplateGuard::assertValid($html, json_decode((string) $row['variables'], true));
                self::assertSame($type->qr, str_contains($html, '{{qr}}'), $type->doc . ' ' . $locale);
            }
        }
    }

    public function testUneEmissionParTypeAvecNumeroQrEtLangue(): void
    {
        $issued = [];
        foreach (['enrolment_form', 'school_certificate', 'school_card'] as $kind) {
            $issued[$kind] = $this->ok('POST', '/secondary/documents', ['kind' => $kind, 'enrollment_id' => 'ins-1'], self::SCOLARITE, self::TA, $this->school);
            self::assertSame('valid', $issued[$kind]['status']);
            self::assertSame('fr', $issued[$kind]['locale']);
        }
        $issued['class_list'] = $this->ok('POST', '/secondary/documents', ['kind' => 'class_list', 'class_id' => $this->classA, 'locale' => 'en'], self::SCOLARITE, self::TA, $this->school);
        self::assertSame('en', $issued['class_list']['locale']);
        self::assertSame('agent-1', $issued['class_list']['subject_person_id'], 'La liste de classe est enregistrée au nom de l\'agent.');
        self::assertCount(4, array_unique(array_column($issued, 'number')));

        $content = fn (string $id): string => (string) ($this->rows('SELECT content FROM documents WHERE id = ?', [$id])[0]['content'] ?? '');
        self::assertStringContainsString('Certificat de scolarité', $content($issued['school_certificate']['id']));
        self::assertStringContainsString('6e A', $content($issued['school_certificate']['id']));
        self::assertStringNotContainsString('FCFA', $content($issued['school_certificate']['id']), 'RG-DOC-4');
        self::assertStringContainsString('MAT-FICTIF-001', $content($issued['school_card']['id']));
        self::assertStringContainsString('Mariam', $content($issued['enrolment_form']['id']));
        self::assertStringContainsString('Class list', $content($issued['class_list']['id']));
        self::assertStringContainsString('EXEMPLE Aïcha', $content($issued['class_list']['id']));

        $verify = $this->kernel->handle(new ServerRequest('GET', '/api/v1/public/verify/' . $issued['school_certificate']['verification_token']));
        self::assertSame(200, $verify->getStatusCode());
        self::assertStringNotContainsString('MAT-FICTIF-001', (string) $verify->getBody(), 'D5 : jamais le matricule sur la page publique.');

        $card = $this->ok('POST', '/secondary/documents', ['kind' => 'school_card', 'enrollment_id' => 'ins-2'], self::SCOLARITE, self::TA, $this->school);
        self::assertStringContainsString('Matricule : en attente', $content($card['id']));

        $list = $this->ok('GET', '/secondary/documents/issued?person_id=eleve-1', null, self::SCOLARITE, self::TA, $this->school)['items'];
        self::assertSame(['DOC-02', 'DOC-03', 'DOC-04'], self::sorted(array_column($list, 'doc')));
        self::assertArrayNotHasKey('content', $list[0]);
    }

    public function testExeatSeulementApresClotureDeLInscription(): void
    {
        $body = ['kind' => 'exeat', 'enrollment_id' => 'ins-1', 'destination' => 'Lycée fictif de Garoua', 'reason' => 'Déménagement', 'effective_on' => '2026-11-02'];
        [$status] = $this->call('POST', '/secondary/documents', $body, self::SCOLARITE, self::TA, $this->school);
        self::assertSame(422, $status, 'Jamais d\'exeat pour un élève encore inscrit.');
        $this->enrollments->add(self::TA, 'ins-3', 'eleve-2', $this->school, $this->classA, 'ay-2026', 'transferred');
        $exeat = $this->ok('POST', '/secondary/documents', ['enrollment_id' => 'ins-3'] + $body, self::SCOLARITE, self::TA, $this->school);
        self::assertTrue($exeat['qr']);
        self::assertSame(422, $this->call('POST', '/secondary/documents', ['kind' => 'exeat', 'enrollment_id' => 'ins-3'], self::SCOLARITE, self::TA, $this->school)[0], 'Destination, motif et date obligatoires.');
    }

    public function testDocumentsFinanciersReleveEtAttestationSansResteDu(): void
    {
        $this->world->invoice('fac-1', $this->school, 'eleve-1', 150000, 60000, 'ins-1', [
            ['id' => 'i1', 'line_id' => 'fac-1-l1', 'label' => 'Tranche 1', 'due_on' => '2026-10-05', 'amount_xaf' => 60000, 'paid_xaf' => 60000],
            ['id' => 'i2', 'line_id' => 'fac-1-l1', 'label' => 'Tranche 2', 'due_on' => '2027-01-05', 'amount_xaf' => 90000, 'paid_xaf' => 0],
        ]);
        $this->world->invoice('fac-2', $this->school, 'eleve-2', 135000, 135000, 'ins-2');
        $this->world->invoice('fac-3', $this->school, 'eleve-2', 10000, 0, 'ins-2');

        [$status] = $this->call('POST', '/secondary/documents', ['kind' => 'account_statement', 'invoice_id' => 'fac-1'], self::SCOLARITE, self::TA, $this->school);
        self::assertSame(403, $status, 'La scolarité n\'émet pas le relevé.');

        $content = fn (string $id): string => (string) ($this->rows('SELECT content FROM documents WHERE id = ?', [$id])[0]['content'] ?? '');
        $statement = $this->ok('POST', '/secondary/documents', ['kind' => 'account_statement', 'invoice_id' => 'fac-1'], self::BURSAR, self::TA, $this->school);
        self::assertStringContainsString('Tranche 2', $content($statement['id']));
        self::assertStringContainsString('90 000 FCFA', $content($statement['id']));

        $partial = $this->ok('POST', '/secondary/documents', ['kind' => 'payment_attestation', 'invoice_id' => 'fac-1'], self::BURSAR, self::TA, $this->school);
        self::assertStringContainsString('60 000 FCFA', $content($partial['id']));
        self::assertStringNotContainsString('90 000', $content($partial['id']), 'RG-DOC-4 : l\'attestation ne mentionne jamais un reste dû.');
        $quitus = $this->ok('POST', '/secondary/documents', ['kind' => 'payment_attestation', 'invoice_id' => 'fac-2', 'locale' => 'en'], self::BURSAR, self::TA, $this->school);
        self::assertStringContainsString('clearance', $content($quitus['id']));
        self::assertSame(422, $this->call('POST', '/secondary/documents', ['kind' => 'payment_attestation', 'invoice_id' => 'fac-3'], self::BURSAR, self::TA, $this->school)[0]);
        self::assertSame(404, $this->call('POST', '/secondary/documents', ['kind' => 'account_statement', 'invoice_id' => 'inconnue'], self::BURSAR, self::TA, $this->school)[0]);
    }

    public function testPerimetreDeClasseIsolationEtContrat(): void
    {
        $this->grant(self::TA, 'agent-6eb', $this->school, ['secondary.documents.issue'], Scope::CLASS_GROUP, $this->classB);
        [$status] = $this->call('POST', '/secondary/documents', ['kind' => 'school_certificate', 'enrollment_id' => 'ins-1'], 'agent-6eb', self::TA, $this->school);
        self::assertSame(403, $status);
        self::assertContains('secondary.scope.denied', $this->audit->actions());
        self::assertSame(403, $this->call('POST', '/secondary/documents', ['kind' => 'school_certificate', 'enrollment_id' => 'ins-1'], 'sans-droit', self::TA, $this->school)[0]);

        $other = $this->factory->opened(self::TB, 'ces');
        $this->grant(self::TB, 'scolarite-b', $other, ['secondary.documents.issue']);
        self::assertSame(404, $this->call('POST', '/secondary/documents', ['kind' => 'school_certificate', 'enrollment_id' => 'ins-1'], 'scolarite-b', self::TB, $other)[0]);
        self::assertSame(422, $this->call('POST', '/secondary/documents', ['kind' => 'receipt', 'enrollment_id' => 'ins-1'], self::SCOLARITE, self::TA, $this->school)[0]);

        $documents = $this->service(SecondaryDocuments::class);
        $pre = $documents->issue(self::TA, $this->school, 'preregistration', 'eleve-2', ['academic_year' => '2026-2027', 'requested_level' => '6e', 'requested_stream' => '', 'entry_test_line' => 'Test d\'entrée : réussi (exemple)'], 'agent-1');
        self::assertSame('sec_doc01_preregistration', $pre->type);
        try {
            $documents->issue(self::TA, $this->school, 'receipt', 'eleve-2', [], 'agent-1');
            self::fail('Le reçu est émis par le socle Billing.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }

    /**
     * @param list<mixed> $docs
     * @return list<string>
     */
    private static function sorted(array $docs): array
    {
        $docs = array_values(array_unique(array_map('strval', $docs)));
        sort($docs);

        return $docs;
    }

    /**
     * @param list<string> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
    }
}
