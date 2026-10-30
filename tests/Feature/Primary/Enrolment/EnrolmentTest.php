<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Enrolment;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentStatusWriter;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\PrimaryTestCase;
use Tests\Support\Primary\RecordingStatuses;

/** EX-PRI-14, 15, 29, 34, 40, 46 : profil d'élève (redoublant), table de passage, facture d'inscription, documents, transfert, démarrage. */
final class EnrolmentTest extends PrimaryTestCase
{
    private InMemoryEnrollments $enrollments;
    private InMemoryPeople $people;
    private RecordingStatuses $statuses;

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->people = new InMemoryPeople();
        $this->statuses = new RecordingStatuses();
        $this->bootPrimary([
            EnrollmentDirectory::class => $this->enrollments,
            PersonDirectory::class => $this->people,
            EnrollmentStatusWriter::class => $this->statuses,
        ]);
        $this->permissions->grant(self::TA, self::DIRECTOR, 'billing.manage', self::EA);
        $this->permissions->grant(self::TA, self::DIRECTOR, 'billing.read', self::EA);
    }

    public function testRedoublantCalculeSurDeuxAnneesDansLeMemeNiveau(): void
    {
        $l = $this->makeLevel(self::TA, self::EA, 'CE1', 2);
        $this->people->add(self::TA, 'eleve1', 'Awa', 'Test');
        $this->enrollments->add(self::TA, 'en-2025', 'eleve1', self::EA, $l['classes'][0], 'AY-2025');
        $this->enrollments->add(self::TA, 'en-2026', 'eleve1', self::EA, $l['classes'][1], 'AY-2026');
        $this->people->add(self::TA, 'eleve2', 'Paul', 'Test');
        $this->enrollments->add(self::TA, 'en-p', 'eleve2', self::EA, $l['classes'][0], 'AY-2026');
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $l['level'], 'is_exam_level' => true]);

        $profile = $this->ok('GET', '/primary/learners/eleve1/profile?academic_year_id=AY-2026');
        self::assertTrue($profile['repeater']);
        self::assertSame($l['classes'][1], $profile['class_structure_id']);
        self::assertTrue($profile['is_exam_level']);
        self::assertFalse($this->ok('GET', '/primary/learners/eleve2/profile')['repeater']);

        // Autre niveau l'année précédente : pas redoublant.
        $m = $this->makeLevel(self::TA, self::EA, 'CP');
        $this->people->add(self::TA, 'eleve3', 'Luc', 'Test');
        $this->enrollments->add(self::TA, 'en-3a', 'eleve3', self::EA, $m['classes'][0], 'AY-2025');
        $this->enrollments->add(self::TA, 'en-3b', 'eleve3', self::EA, $l['classes'][0], 'AY-2026');
        self::assertFalse($this->ok('GET', '/primary/learners/eleve3/profile?academic_year_id=AY-2026')['repeater']);
    }

    public function testProfilIsoleParClientEtParClasse(): void
    {
        $l = $this->makeLevel(self::TA, self::EA, 'CE2', 2);
        $this->people->add(self::TA, 'eleve1', 'Awa', 'Test');
        $this->enrollments->add(self::TA, 'en1', 'eleve1', self::EA, $l['classes'][0], 'AY-2026');

        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('GET', '/primary/learners/eleve1/profile', null, 'directeur-b', self::TB, self::EB)[0]);

        $this->assign(self::TA, 'maitre2', self::EA, 'primary.learner.read', Scope::CLASS_GROUP, $l['classes'][1]);
        self::assertSame(404, $this->call('GET', '/primary/learners/eleve1/profile', null, 'maitre2')[0], 'Maître d\'une autre classe.');
        $this->assign(self::TA, 'maitre1', self::EA, 'primary.learner.read', Scope::CLASS_GROUP, $l['classes'][0]);
        self::assertSame(200, $this->call('GET', '/primary/learners/eleve1/profile', null, 'maitre1')[0]);
    }

    public function testTableDePassageIncoherenteEstSignalee(): void
    {
        $a = $this->makeLevel(self::TA, self::EA, 'N1');
        $b = $this->makeLevel(self::TA, self::EA, 'N2');
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $a['level'], 'sort_order' => 1]);
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $b['level'], 'sort_order' => 2, 'is_final_level' => true]);

        $check = $this->ok('GET', '/primary/promotion-map/check');
        self::assertFalse($check['ok']);
        self::assertSame('missing_next_class', $check['errors'][0]['code']);

        $writer = $this->service(SettingsWriter::class);
        $writer->presetIfAbsent(self::TA, self::EA, 'people.promotion.next_structure', [$a['classes'][0] => $b['classes'][0]], self::DIRECTOR);
        self::assertTrue($this->ok('GET', '/primary/promotion-map/check')['ok']);

        $this->service(\Scholaris\Modules\Socle\Organisation\Application\SettingsService::class)->set(
            \Scholaris\Modules\Socle\Organisation\Domain\SettingScope::Establishment,
            self::TA,
            self::EA,
            'people.promotion.next_structure',
            [$a['classes'][0] => $b['classes'][0], $b['classes'][0] => $a['classes'][0]],
            self::DIRECTOR,
        );
        $codes = array_column($this->ok('GET', '/primary/promotion-map/check')['errors'], 'code');
        self::assertSame(['final_level_has_next'], $codes);
    }

    public function testFactureDInscriptionAvecPremiereTrancheEtRemiseFratrie(): void
    {
        $this->seedGrid();
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'billing.sibling_discount', ['mode' => 'percent', 'value' => 10], self::DIRECTOR);
        $l = $this->makeLevel(self::TA, self::EA, 'CM1');
        $this->people->add(self::TA, 'parent', 'Parent', 'Test', '+237670000000');
        foreach (['ainee', 'cadet'] as $learner) {
            $this->people->add(self::TA, $learner, ucfirst($learner), 'Test');
            $this->people->link(self::TA, 'parent', $learner);
            $this->enrollments->add(self::TA, 'en-' . $learner, $learner, self::EA, $l['classes'][0], 'ay1');
        }

        $first = $this->ok('POST', '/primary/enrolments/en-ainee/invoice');
        self::assertSame(500000, $first['gross_xaf']);
        self::assertSame(50000 + 150000, $first['due_at_enrolment_xaf'], 'Inscription + 1re tranche.');
        $second = $this->ok('POST', '/primary/enrolments/en-cadet/invoice');
        self::assertGreaterThan(0, $second['discount_xaf'], 'Remise fratrie au 2e enfant.');
        self::assertSame(45000, $second['discount_xaf']);

        // Isolation : un autre client ne facture pas cette inscription.
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('POST', '/primary/enrolments/en-cadet/invoice', null, 'directeur-b', self::TB, self::EB)[0]);
        self::assertSame(['pta_fee', 'uniform', 'supplies', 'exam_fee', 'canteen', 'daycare', 'transport'], $this->ok('GET', '/primary/billing/fee-kinds')['items']);
    }

    public function testCertificatDeScolariteQrEtBilingue(): void
    {
        $l = $this->makeLevel(self::TA, self::EA, 'CM2');
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $l['level']]);
        $this->people->add(self::TA, 'eleve1', 'Awa', 'Test');
        $this->people->add(self::TA, 'tuteur', 'Marie', 'Test', '+237650000000');
        $this->people->link(self::TA, 'tuteur', 'eleve1');
        $this->enrollments->add(self::TA, 'en1', 'eleve1', self::EA, $l['classes'][0], 'AY-2026');

        $fr = $this->ok('POST', '/primary/documents', ['kind' => 'certificate', 'enrollment_id' => 'en1']);
        self::assertSame('pri_school_certificate', $fr['type']);
        self::assertNotSame('', $fr['verification_token']);
        $en = $this->ok('POST', '/primary/documents', ['kind' => 'attendance', 'enrollment_id' => 'en1', 'locale' => 'en']);
        self::assertSame('pri_attendance_letter', $en['type']);
        self::assertNotSame($fr['number'], $en['number']);
        self::assertSame('valid', $this->ok('POST', '/primary/documents', ['kind' => 'school_card', 'enrollment_id' => 'en1'])['status']);
        self::assertSame('valid', $this->ok('POST', '/primary/documents', ['kind' => 'info_sheet', 'enrollment_id' => 'en1'])['status']);

        // Vérification publique par le jeton du QR (route du socle).
        $verify = $this->kernel->handle(new \Nyholm\Psr7\ServerRequest('GET', '/api/v1/public/verify/' . $fr['verification_token']));
        self::assertSame(200, $verify->getStatusCode());

        self::assertSame(422, $this->call('POST', '/primary/documents', ['kind' => 'transfer', 'enrollment_id' => 'en1'])[0]);
        self::assertSame(404, $this->call('POST', '/primary/documents', ['kind' => 'certificate', 'enrollment_id' => 'absent'])[0]);
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('POST', '/primary/documents', ['kind' => 'certificate', 'enrollment_id' => 'en1'], 'directeur-b', self::TB, self::EB)[0]);
        self::assertContains($fr['type'], array_column($this->ok('GET', '/primary/documents/kinds')['items'], 'type'));
    }

    public function testTransfertSortantEmetUnDocumentEtClotLInscription(): void
    {
        $l = $this->makeLevel(self::TA, self::EA, 'CE1');
        $this->people->add(self::TA, 'eleve1', 'Awa', 'Test');
        $this->enrollments->add(self::TA, 'en1', 'eleve1', self::EA, $l['classes'][0], 'AY-2026');

        $out = $this->ok('POST', '/primary/enrolments/en1/transfer', ['destination' => 'École fictive de Maroua', 'reason' => 'Déménagement', 'effective_on' => '2026-11-02']);
        self::assertSame('transferred', $out['enrollment_status']);
        self::assertSame(['en1:transferred'], $this->statuses->calls);
        self::assertContains('primary.transfer.out', $this->audit->actions());

        // Statut refusé par le socle : le document est révoqué.
        $this->statuses->failNext = true;
        $this->enrollments->add(self::TA, 'en2', 'eleve1', self::EA, $l['classes'][0], 'AY-2027');
        self::assertSame(409, $this->call('POST', '/primary/enrolments/en2/transfer', ['destination' => 'Ailleurs', 'reason' => 'Autre', 'effective_on' => '2026-11-03'])[0]);
        self::assertSame(422, $this->call('POST', '/primary/enrolments/en2/transfer', ['destination' => 'Ailleurs'])[0]);
    }

    public function testDemarragePreregleLAppelParDemiJourneSansEcraser(): void
    {
        $status = $this->ok('GET', '/primary/setup');
        self::assertFalse($status['ready']);
        $applied = $this->ok('POST', '/primary/setup/apply');
        self::assertSame(['attendance.mode'], $applied['applied']);
        self::assertTrue($applied['ready']);
        self::assertSame('day_part', $this->service(SettingsReader::class)->value(self::TA, self::EA, 'attendance.mode'));
        self::assertSame([], $this->ok('POST', '/primary/setup/apply')['applied'], 'Idempotent.');

        // Valeur déjà choisie ailleurs : conservée.
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TB, self::EB, 'attendance.mode', 'slot', 'admin');
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame([], $this->ok('POST', '/primary/setup/apply', null, 'directeur-b', self::TB, self::EB)['applied']);
        self::assertSame('slot', $this->service(SettingsReader::class)->value(self::TB, self::EB, 'attendance.mode'));

        $this->assign(self::TA, 'maitre', self::EA, 'attendance.take', Scope::CLASS_GROUP, 'une-classe');
        self::assertSame(200, $this->call('GET', '/primary/setup', null, 'maitre')[0]);
        self::assertSame(403, $this->call('POST', '/primary/setup/apply', null, 'maitre')[0]);
    }

    private function seedGrid(): void
    {
        $registration = $this->ok('POST', '/fee-items', ['kind' => 'registration', 'name' => 'Frais d\'inscription', 'amount_xaf' => 50000])['id'];
        $tuition = $this->ok('POST', '/fee-items', ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 450000])['id'];
        $this->ok('POST', '/fee-schedules', ['fee_item_id' => $registration, 'academic_year_id' => 'ay1', 'amount_xaf' => 50000, 'terms' => [['due_on' => '2026-10-08', 'amount' => 50000]]]);
        $this->ok('POST', '/fee-schedules', [
            'fee_item_id' => $tuition, 'academic_year_id' => 'ay1', 'amount_xaf' => 450000,
            'terms' => [['due_on' => '2026-10-15', 'amount' => 150000], ['due_on' => '2027-01-15', 'amount' => 150000], ['due_on' => '2027-04-15', 'amount' => 150000]],
        ]);
    }
}
