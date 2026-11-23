<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Documents;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Documents\Application\DocumentTypeCatalogue;
use Scholaris\Modules\Apps\Secondary\Documents\Application\TechnicalDocumentTypes;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoProfileStore;
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
 * T833/T913 : documents E1 et catalogue E3-F (DOC-21 à DOC-24) : émission E1 par le socle Documents, types E3 caller-issued,
 * disponibilité liée aux modules/capacités, numéro unique, QR selon le CDC,
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
        $e1 = ['DOC-01' => false, 'DOC-02' => false, 'DOC-03' => true, 'DOC-04' => true, 'DOC-05' => true, 'DOC-06' => false, 'DOC-27' => true, 'DOC-28' => false, 'DOC-29' => true];
        self::assertSame($e1, array_intersect_key($qr, $e1));
        $byKind = array_column($items, null, 'kind');
        self::assertFalse($byKind['receipt']['direct'], 'Le reçu DOC-27 est émis par le socle Billing.');
        self::assertFalse($byKind['preregistration']['direct'], 'DOC-01 : par le contrat, depuis le parcours de préinscription.');
        self::assertTrue($byKind['school_certificate']['can_issue']);
        self::assertFalse($byKind['account_statement']['can_issue'], 'La scolarité n\'émet pas les documents financiers.');
        self::assertTrue($byKind['school_certificate']['available']);
    }

    public function testDocumentsE3SelonLesModulesEtLEnseignementTechnique(): void
    {
        $technical = $this->factory->opened(self::TA, 'lycee_technique');
        $this->grant(self::TA, self::SCOLARITE, $technical, ['secondary.documents.issue']);
        $expected = ['DOC-21' => [true, 'internships'], 'DOC-22' => [false, 'internships'], 'DOC-23' => [false, 'workshops'], 'DOC-24' => [false, 'workshops'], 'DOC-30' => [false, 'payroll_simple']];
        $general = array_column($this->ok('GET', '/secondary/documents/kinds', null, self::SCOLARITE, self::TA, $this->school)['items'], null, 'doc');
        $tech = array_column($this->ok('GET', '/secondary/documents/kinds', null, self::SCOLARITE, self::TA, $technical)['items'], null, 'doc');
        foreach ($expected as $doc => [$qr, $module]) {
            self::assertSame([$qr, $module, false], [$general[$doc]['qr'], $general[$doc]['module'], $general[$doc]['direct']], $doc);
            self::assertFalse($general[$doc]['available'], $doc . ' : module inactif au lycée général.');
        }
        foreach (['DOC-21', 'DOC-22', 'DOC-23', 'DOC-24'] as $doc) {
            self::assertTrue($tech[$doc]['available'], $doc . ' : modules et enseignement technique du profil.');
            self::assertSame('technical', $tech[$doc]['required_teaching_kind']);
        }
        self::assertFalse($tech['DOC-30']['available'], 'DOC-30 seulement avec payroll_simple.');
        self::assertSame(422, $this->call('POST', '/secondary/documents', ['kind' => TechnicalDocumentTypes::WORKSHOP_SHEET, 'class_id' => $this->classA], self::SCOLARITE, self::TA, $technical)[0], 'Émis par le module propriétaire, pas par cette route.');

        // Lycée général qui active les ateliers : le module ne suffit pas, l'enseignement technique est exigé.
        $this->service(PdoProfileStore::class)->saveProfile(self::TA, $this->school, 'test', ['modules' => ['second_cycle', 'orientation', 'official_exams', 'fees_public_catalog', 'workshops']], [], 'Activation fictive des ateliers', 'admin-a');
        // FIX-SEC, défaut 6 : le catalogue dit pourquoi un type est indisponible, au lieu de « module inactif » pour un module actif.
        $general = array_column($this->ok('GET', '/secondary/documents/kinds', null, self::SCOLARITE, self::TA, $this->school)['items'], null, 'doc');
        self::assertSame([false, 'teaching_kind_not_offered'], [$general['DOC-23']['available'], $general['DOC-23']['unavailable_reason']], 'Ateliers actifs au LEG : réservé à l\'enseignement technique.');
        self::assertSame('module_inactive', $general['DOC-21']['unavailable_reason'], 'Stages inactifs.');
        self::assertNull($tech['DOC-23']['unavailable_reason']);
        $documents = $this->service(SecondaryDocuments::class);
        try {
            $documents->issue(self::TA, $this->school, TechnicalDocumentTypes::WORKSHOP_SHEET, 'eleve-1', [], 'agent-1');
            self::fail('Ateliers actifs sans enseignement technique : refus attendu.');
        } catch (HttpException $e) {
            self::assertSame([409, 'profile_capability_not_offered'], [$e->status, $e->extensions['code'] ?? null]);
        }
        try {
            $documents->issue(self::TA, $this->school, TechnicalDocumentTypes::INTERNSHIP_AGREEMENT, 'eleve-1', [], 'agent-1');
            self::fail('Module internships inactif : refus attendu.');
        } catch (HttpException $e) {
            self::assertSame([409, 'module_not_enabled'], [$e->status, $e->extensions['code'] ?? null]);
        }
    }

    public function testConventionDeStageEmiseParContratEtVerificationPubliqueSansContenu(): void
    {
        $technical = $this->factory->opened(self::TA, 'lycee_technique');
        $issued = $this->service(SecondaryDocuments::class)->issue(self::TA, $technical, TechnicalDocumentTypes::INTERNSHIP_AGREEMENT, 'eleve-1', [
            'academic_year' => '2026-2027', 'class_name' => '1re F3', 'company_name' => 'Atelier Fictif SARL', 'company_address' => 'Quartier Exemple',
            'tutor_name' => 'Tuteur Exemple', 'starts_on' => '01/03/2027', 'ends_on' => '31/03/2027', 'activities_block' => 'Montage fictif 15/20',
        ], 'agent-1', 'fr');
        self::assertSame('sec_doc21_internship_agreement', $issued->type);
        $content = (string) ($this->rows('SELECT content FROM documents WHERE id = ?', [$issued->id])[0]['content'] ?? '');
        self::assertStringContainsString('Atelier Fictif SARL', $content);
        $verify = $this->kernel->handle(new ServerRequest('GET', '/api/v1/public/verify/' . $issued->verificationToken));
        self::assertSame(200, $verify->getStatusCode());
        $body = (string) $verify->getBody();
        foreach (['Atelier Fictif SARL', 'Tuteur Exemple', '15/20', 'Montage', '1re F3'] as $hidden) {
            self::assertStringNotContainsString($hidden, $body, 'RG-DOC-2 : la page publique ne montre aucun contenu ni note.');
        }
    }

    public function testLesModelesDuSocleExistentEnFrancaisEtEnAnglaisSansPointVirgule(): void
    {
        $catalogue = $this->service(DocumentTypeCatalogue::class);
        // Types de ce module (E1, E3 technique) et DOC-30 de Staff ; les autres sous-modules vérifient leurs propres types.
        $owned = ['DOC-01', 'DOC-02', 'DOC-03', 'DOC-04', 'DOC-05', 'DOC-06', 'DOC-21', 'DOC-22', 'DOC-23', 'DOC-24', 'DOC-28', 'DOC-29', 'DOC-30'];
        $seen = [];
        foreach ($catalogue->all() as $type) {
            if ($type->socleCode === null || !in_array($type->doc, $owned, true)) {
                continue;
            }
            $seen[] = $type->doc;
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
        sort($seen);
        self::assertSame($owned, $seen);
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
        $candidates = $this->ok('GET', '/secondary/documents/candidates?class_id=' . $this->classA, null, self::SCOLARITE, self::TA, $this->school)['items'];
        self::assertContains('ins-3', array_column($candidates, 'enrollment_id'));
        self::assertSame('EXEMPLE Aïcha', $candidates[0]['name']);
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
