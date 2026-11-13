<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Portals;

use Scholaris\Modules\Apps\Secondary\Portals\Application\SecondaryPortalSection;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Secondary\Portals\PortalWorld;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T834 : portails élève et parent du secondaire. Chacun lit par son propre périmètre (secondary.self.read, secondary.family.read) ;
 * le parent ne voit que ses enfants ; le paiement (secondary.family.pay) ne vise qu'une facture d'un de ses enfants ; le matricule
 * n'est jamais exposé (D5) ; accès restreint d'un élève majeur sans consentement ; documents retenus en cas de blocage. Données fictives.
 */
final class SecondaryPortalsTest extends SecondaryTestCase
{
    private const PARENT = 'compte-parent-1';
    private const OTHER_PARENT = 'compte-parent-2';
    private const STUDENT = 'compte-eleve-1';

    private InMemoryEnrollments $enrollments;
    private InMemoryPeople $people;
    private PortalWorld $world;
    private string $school;

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->people = new InMemoryPeople();
        $this->world = new PortalWorld();
        $this->bootSecondary([
            EnrollmentDirectory::class => $this->enrollments,
            PersonDirectory::class => $this->people,
            InvoiceIssuer::class => $this->world,
            PaymentRequester::class => $this->world,
            DebtorDirectory::class => $this->world,
            UserPersonLink::class => $this->world,
            AdultLearnerCheck::class => $this->world,
            ConsentChecker::class => $this->world,
            'secondary.student_facts.test' => [$this->world],
            'secondary.family_invoices.test' => [$this->world],
        ]);
        $this->school = $this->factory->opened(self::TA, 'college_prive');
        $writer = $this->service(StructureWriter::class);
        $level = $writer->createStructure(self::TA, $this->school, new StructureDraft(StructureDraft::LEVEL, '5e'));
        $class = $writer->createStructure(self::TA, $this->school, new StructureDraft(StructureDraft::SCHOOL_CLASS, '5e A', null, $level));

        foreach ([['eleve-1', 'Aïcha'], ['eleve-2', 'Brice'], ['eleve-3', 'Carine'], ['parent-1', 'Mariam'], ['parent-2', 'Joseph']] as [$id, $first]) {
            $this->people->add(self::TA, $id, $first, 'Exemple');
        }
        $this->people->link(self::TA, 'parent-1', 'eleve-1');
        $this->people->link(self::TA, 'parent-1', 'eleve-2');
        $this->people->link(self::TA, 'parent-2', 'eleve-3');
        foreach (['eleve-1', 'eleve-2', 'eleve-3'] as $i => $learner) {
            $this->enrollments->add(self::TA, 'ins-' . ($i + 1), $learner, $this->school, $class, 'ay-2026');
        }
        $this->world->link(self::TA, self::PARENT, 'parent-1');
        $this->world->link(self::TA, self::OTHER_PARENT, 'parent-2');
        $this->world->link(self::TA, self::STUDENT, 'eleve-1');
        $this->world->nationalIds['eleve-1'] = 'MAT-FICTIF-001';
        $this->world->balances = ['eleve-1' => 90000, 'eleve-2' => 0, 'eleve-3' => 40000];
        $this->world->invoice('fac-1', $this->school, 'eleve-1', 150000, 60000, 'ins-1');
        $this->world->invoice('fac-3', $this->school, 'eleve-3', 40000, 0, 'ins-3');
        foreach ([self::PARENT, self::OTHER_PARENT] as $user) {
            $this->grant(self::TA, $user, $this->school, ['secondary.family.read', 'secondary.family.pay']);
        }
        $this->grant(self::TA, self::STUDENT, $this->school, ['secondary.self.read']);
    }

    public function testLeParentNeVoitQueSesEnfantsEtJamaisLeMatricule(): void
    {
        $data = $this->ok('GET', '/secondary/portal/family', null, self::PARENT, self::TA, $this->school);
        self::assertSame(['eleve-1', 'eleve-2'], array_column(array_column($data['children'], 'person'), 'id'));
        self::assertSame(90000, $data['family_balance_xaf']);
        self::assertTrue($data['can_pay']);
        $first = $data['children'][0];
        self::assertSame('5e A', $first['enrollment']['class']['name']);
        self::assertTrue($first['national_id_registered']);
        self::assertFalse($data['children'][1]['national_id_registered']);
        self::assertSame('fees_private', $first['fees_mode']);
        self::assertSame(['fac-1'], array_column($first['invoices'], 'id'));
        self::assertSame('Pension (exemple)', $first['invoices'][0]['label'], 'FIX-SEC : facture nommée par ses frais, jamais par son identifiant.');
        self::assertStringNotContainsString('MAT-FICTIF-001', (string) json_encode($data), 'D5');

        self::assertSame(403, $this->call('GET', '/secondary/portal/family', null, self::STUDENT, self::TA, $this->school)[0], 'L\'élève n\'a pas le droit famille.');
        self::assertSame(403, $this->call('GET', '/secondary/portal/family', null, 'personnel-sans-droit', self::TA, $this->school)[0]);
    }

    public function testLeParentVoitSaReferenceDePaiementEtJamaisCelleDUneAutreFamille(): void
    {
        self::assertNull($this->ok('GET', '/secondary/portal/family', null, self::PARENT, self::TA, $this->school)['payment_reference'], 'Aucune référence émise.');
        $insert = $this->pdo()->prepare('INSERT INTO payment_references (id, tenant_id, establishment_id, guarantor_id, reference, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute(['01DBULREF00000000000000011', self::TA, $this->school, 'parent-1', 'FAM-9011', '2026-11-12T09:00:00+01:00']);
        $insert->execute(['01DBULREF00000000000000012', self::TA, $this->school, 'parent-2', 'FAM-9012', '2026-11-12T09:00:00+01:00']);
        $insert->execute(['01DBULREF00000000000000013', self::TB, 'autre-ecole', 'parent-1', 'FAM-9013', '2026-11-12T09:00:00+01:00']);

        $data = $this->ok('GET', '/secondary/portal/family', null, self::PARENT, self::TA, $this->school);

        self::assertSame('FAM-9011', $data['payment_reference']);
        self::assertStringNotContainsString('FAM-9012', (string) json_encode($data), 'Jamais la référence d\'une autre famille.');
        self::assertStringNotContainsString('FAM-9013', (string) json_encode($data), 'Jamais celle d\'un autre client.');
        self::assertSame('FAM-9012', $this->ok('GET', '/secondary/portal/family', null, self::OTHER_PARENT, self::TA, $this->school)['payment_reference']);
    }

    public function testLElevePortailEtDocumentsRetenusEnCasDeBlocage(): void
    {
        $data = $this->ok('GET', '/secondary/portal/student', null, self::STUDENT, self::TA, $this->school);
        self::assertSame('eleve-1', $data['person']['id']);
        self::assertSame(['blocked' => false, 'items' => []], $data['documents']);
        self::assertArrayNotHasKey('invoices', $data);
        self::assertSame(403, $this->call('GET', '/secondary/portal/student', null, self::PARENT, self::TA, $this->school)[0]);

        $this->world->blocked['eleve-1'] = true;
        $blocked = $this->ok('GET', '/secondary/portal/student', null, self::STUDENT, self::TA, $this->school);
        self::assertTrue($blocked['documents']['blocked']);
        self::assertSame('5e A', $blocked['enrollment']['class']['name'], 'RG-FIN-1 : le blocage ne retire jamais l\'inscription.');
    }

    public function testEleveMajeurSansConsentementAccesRestreint(): void
    {
        $this->world->adults['eleve-2'] = true;
        $children = $this->ok('GET', '/secondary/portal/family', null, self::PARENT, self::TA, $this->school)['children'];
        self::assertSame('restricted', $children[1]['access']);
        self::assertArrayNotHasKey('documents', $children[1]);
        $this->world->consents['eleve-2'] = true;
        self::assertSame('full', $this->ok('GET', '/secondary/portal/family', null, self::PARENT, self::TA, $this->school)['children'][1]['access']);
    }

    public function testPaiementLimiteAuxFacturesDeSesEnfantsEtIdempotent(): void
    {
        $body = ['invoice_id' => 'fac-1', 'amount_xaf' => 30000, 'channel' => 'orange_money', 'payer_phone' => '+237 600 000 001', 'idempotency_key' => 'cle-1'];
        $paid = $this->ok('POST', '/secondary/portal/family/children/eleve-1/payments', $body, self::PARENT, self::TA, $this->school);
        self::assertSame('pending', $paid['status']);
        self::assertFalse($paid['replayed']);
        self::assertTrue($this->ok('POST', '/secondary/portal/family/children/eleve-1/payments', $body, self::PARENT, self::TA, $this->school)['replayed']);
        self::assertSame('pending', $this->ok('GET', '/secondary/portal/family/children/eleve-1/payments/' . $paid['id'], null, self::PARENT, self::TA, $this->school)['status']);
        self::assertContains('secondary.portal.payment.requested', $this->audit->actions());

        self::assertSame(404, $this->call('POST', '/secondary/portal/family/children/eleve-3/payments', ['invoice_id' => 'fac-3'] + $body, self::PARENT, self::TA, $this->school)[0], 'Enfant d\'une autre famille.');
        self::assertSame(404, $this->call('POST', '/secondary/portal/family/children/eleve-2/payments', $body, self::PARENT, self::TA, $this->school)[0], 'Facture d\'un autre enfant.');
        self::assertSame(404, $this->call('GET', '/secondary/portal/family/children/eleve-1/payments/' . $paid['id'], null, self::OTHER_PARENT, self::TA, $this->school)[0]);
        self::assertSame(422, $this->call('POST', '/secondary/portal/family/children/eleve-1/payments', ['amount_xaf' => 999999, 'idempotency_key' => 'cle-2'] + $body, self::PARENT, self::TA, $this->school)[0], 'Au-delà du reste dû.');
        self::assertSame(422, $this->call('POST', '/secondary/portal/family/children/eleve-1/payments', ['payer_phone' => 'abc'] + $body, self::PARENT, self::TA, $this->school)[0]);

        $this->grant(self::TA, 'lecture-seule', $this->school, ['secondary.family.read']);
        $this->world->link(self::TA, 'lecture-seule', 'parent-1');
        self::assertFalse($this->ok('GET', '/secondary/portal/family', null, 'lecture-seule', self::TA, $this->school)['can_pay']);
        self::assertSame(403, $this->call('POST', '/secondary/portal/family/children/eleve-1/payments', $body, 'lecture-seule', self::TA, $this->school)[0]);
    }

    public function testIsolationEntreClientsEtSectionDuPortailDuSocle(): void
    {
        $other = $this->factory->opened(self::TB, 'lycee_prive');
        $this->grant(self::TB, self::PARENT, $other, ['secondary.family.read']);
        $this->world->link(self::TB, self::PARENT, 'parent-1');
        self::assertSame([], $this->ok('GET', '/secondary/portal/family', null, self::PARENT, self::TB, $other)['children']);

        $section = $this->service(SecondaryPortalSection::class);
        $built = $section->build(new PortalSectionContext(self::TA, PortalSection::FAMILY, 'parent-1', 'eleve-1', true));
        self::assertIsArray($built);
        self::assertSame($this->school, $built['establishments'][0]['establishment_id']);
        self::assertArrayNotHasKey('invoices', $built['establishments'][0]);
        self::assertNull($section->build(new PortalSectionContext(self::TA, PortalSection::FAMILY, 'parent-1', 'parent-1', true)), 'Personne hors du secondaire.');
        self::assertContains(SecondaryPortalSection::class, $this->kernel->container()->get('portals.sections.secondary'));
    }
}
