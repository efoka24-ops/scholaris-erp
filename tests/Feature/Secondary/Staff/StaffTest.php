<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Staff;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Staff\Application\SecondaryPayabilityCheck;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentPdfReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;
use Scholaris\Modules\Socle\Staff\Contracts\VacationPayabilityCheck;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T911, T912, T955 : statuts du personnel, heures du mois (appel, socle, cahier de textes), heures payables seulement avec appel et
 * séance signée, paie simplifiée seulement avec payroll_simple (collège et lycée privés), jamais un fonctionnaire, net = brut -
 * retenues, DOC-30 au paiement. Données fictives.
 */
final class StaffTest extends SecondaryTestCase
{
    private const CHEF = 'chef-a';
    private const INTENDANT = 'intendant-a';
    private const CENSEUR = 'censeur-a';

    private StaffWorld $world;
    private string $leg;
    private string $college;

    protected function setUp(): void
    {
        $this->world = new StaffWorld();
        $this->bootSecondary([
            EstablishmentStaffRoster::class => $this->world,
            DocumentPdfReader::class => $this->world,
            PersonDirectory::class => new StaffPeople($this->world),
            TeacherWorkload::class => $this->world,
            SecondaryDocuments::class => $this->world,
        ]);
        $this->leg = $this->factory->opened(self::TA, 'leg');
        $this->college = $this->factory->opened(self::TA, 'college_prive', 'private_secular');
        $this->world->add(self::TA, $this->leg, 'person-civil', 'staff-civil', 'Emile Civil');
        $this->world->add(self::TA, $this->leg, 'person-gone', 'staff-gone', 'Paul Parti', 'permanent', 'left');
        $this->world->add(self::TA, $this->college, 'person-salarie', 'staff-salarie', 'Nadia Salarie');
        $this->world->add(self::TA, $this->college, 'person-vacataire', 'staff-vacataire', 'Ali Vacataire', 'vacataire');
        $this->world->add(self::TA, $this->college, 'person-contract', 'staff-contract', 'Rose Contrat');
        $this->world->add(self::TA, $this->college, 'person-civil', 'staff-civil', 'Emile Civil');
        $this->world->add(self::TB, 'e-autre-client', 'person-other', 'staff-other', 'Moussa Autre');
        foreach ([$this->leg, $this->college] as $school) {
            $this->grant(self::TA, self::CHEF, $school, ['secondary.settings.manage', 'secondary.settings.read']);
            $this->grant(self::TA, self::INTENDANT, $school, ['secondary.billing.manage', 'secondary.finance.read', 'secondary.settings.read']);
            $this->grant(self::TA, self::CENSEUR, $school, ['secondary.settings.read', 'secondary.syllabus.visa']);
        }
    }

    public function testProfilsDesMembresActifsHeuresDuMoisEtIsolation(): void
    {
        $roster = $this->ok('GET', '/secondary/staff/profiles', null, self::CENSEUR, self::TA, $this->leg)['items'];
        self::assertSame(['person-civil'], array_column($roster, 'person_id'), 'Seuls les membres actifs de l\'établissement.');
        self::assertNull($roster[0]['employment_status']);
        $saved = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-civil', 'employment_status' => 'civil_servant', 'grade_label' => 'PLEG fictif'], self::CHEF, self::TA, $this->leg);
        self::assertSame(['staff-civil', 'civil_servant', 'PLEG fictif', 'Emile Civil'], [$saved['staff_id'], $saved['employment_status'], $saved['grade_label'], $saved['full_name']]);
        self::assertSame(403, $this->call('PUT', '/secondary/staff/profiles', ['person_id' => 'person-civil', 'employment_status' => 'hourly'], self::CENSEUR, self::TA, $this->leg)[0], 'Le censeur lit, le chef d\'établissement tient les profils.');
        foreach (['person-other', 'person-salarie', 'person-gone'] as $foreign) {
            self::assertSame(404, $this->call('PUT', '/secondary/staff/profiles', ['person_id' => $foreign, 'employment_status' => 'private_employee'], self::CHEF, self::TA, $this->leg)[0], $foreign . ' : autre client, autre établissement ou parti.');
        }
        self::assertSame(422, $this->call('PUT', '/secondary/staff/profiles', ['person_id' => 'person-civil', 'employment_status' => 'stagiaire'], self::CHEF, self::TA, $this->leg)[0]);

        $this->lessonLog('log-1', $this->leg, 'person-civil', 'slot-1', '2026-10-12', 1.5);
        $this->lessonLog('log-2', $this->leg, 'person-civil', 'slot-2', '2026-11-02', 1.0);
        $hours = $this->ok('GET', '/secondary/staff/hours?month=2026-10', null, self::CENSEUR, self::TA, $this->leg)['items'];
        self::assertSame([[60, 120, 0, 90]], array_map(static fn (array $h): array => [$h['declared_minutes'], $h['validated_minutes'], $h['rejected_minutes'], $h['logged_minutes']], $hours));
        self::assertSame(422, $this->call('GET', '/secondary/staff/hours?month=2026-13', null, self::CENSEUR, self::TA, $this->leg)[0]);
        self::assertSame(403, $this->call('GET', '/secondary/staff/profiles', null, self::CHEF, self::TB, $this->leg)[0], 'Autre client.');
    }

    public function testPaieSimplifieeDuCollegePriveCycleCompletEtDoc30(): void
    {
        $profile = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-salarie', 'employment_status' => 'private_employee', 'payroll_ref' => 'EMP-FICTIF-01'], self::CHEF, self::TA, $this->college);
        self::assertSame(['private_employee', 'hourly'], $this->ok('GET', '/secondary/staff/payroll/rules', null, self::INTENDANT, self::TA, $this->college)['eligible_statuses'], 'Valeur de départ : salarié et vacataire.');
        self::assertSame(403, $this->call('PUT', '/secondary/staff/payroll/rules', ['eligible_statuses' => ['hourly']], self::INTENDANT, self::TA, $this->college)[0]);
        self::assertSame(422, $this->call('PUT', '/secondary/staff/payroll/rules', ['eligible_statuses' => ['civil_servant']], self::CHEF, self::TA, $this->college)[0], 'Le fonctionnaire n\'est jamais éligible.');

        $slip = $this->ok('POST', '/secondary/staff/payroll/payslips', [
            'staff_profile_id' => $profile['id'], 'month_ref' => '2026-10', 'gross_amount' => 250000,
            'deductions' => [['label' => 'Avance fictive', 'amount' => 15000], ['label' => 'Retenue fictive', 'amount' => 5000]],
        ], self::INTENDANT, self::TA, $this->college);
        self::assertSame([20000, 230000, 'draft'], [$slip['deductions_amount'], $slip['net_amount'], $slip['status']], 'net = brut - retenues');
        self::assertSame(409, $this->call('POST', '/secondary/staff/payroll/payslips/' . $slip['id'] . '/pay', [], self::INTENDANT, self::TA, $this->college)[0], 'Payé seulement après validation.');
        self::assertSame(403, $this->call('POST', '/secondary/staff/payroll/payslips/' . $slip['id'] . '/validate', [], self::INTENDANT, self::TA, $this->college)[0], 'L\'intendant ne valide pas.');
        self::assertSame('validated', $this->ok('POST', '/secondary/staff/payroll/payslips/' . $slip['id'] . '/validate', [], self::CHEF, self::TA, $this->college)['status']);
        $paid = $this->ok('POST', '/secondary/staff/payroll/payslips/' . $slip['id'] . '/pay', ['locale' => 'fr'], self::INTENDANT, self::TA, $this->college);
        self::assertSame(['paid', '01J00000000000000000000000', 'SCH-2026-W-000001'], [$paid['status'], $paid['document_id'], $paid['document_number']]);
        self::assertSame('payroll_payslip', $this->world->issued[0]['kind']);
        self::assertSame('person-salarie', $this->world->issued[0]['person']);
        self::assertSame(['period_month' => '2026-10', 'payroll_ref' => 'EMP-FICTIF-01', 'gross_amount' => '250 000 FCFA', 'deductions_block' => "Avance fictive : 15 000 FCFA\nRetenue fictive : 5 000 FCFA", 'deductions_amount' => '20 000 FCFA', 'net_amount' => '230 000 FCFA'], $this->world->issued[0]['values']);
        self::assertSame(409, $this->call('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $profile['id'], 'month_ref' => '2026-10', 'gross_amount' => 1000], self::INTENDANT, self::TA, $this->college)[0], 'Un bulletin par membre et par mois.');
        self::assertSame(422, $this->call('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $profile['id'], 'month_ref' => '2026-11', 'gross_amount' => 10000, 'deductions' => [['label' => 'Trop', 'amount' => 12000]]], self::INTENDANT, self::TA, $this->college)[0], 'Retenues supérieures au brut.');
        self::assertCount(1, $this->ok('GET', '/secondary/staff/payroll/payslips?month=2026-10', null, self::INTENDANT, self::TA, $this->college)['items']);
    }

    public function testFonctionnaireEtStatutNonRetenuExclusModuleExige(): void
    {
        $civil = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-civil', 'employment_status' => 'civil_servant'], self::CHEF, self::TA, $this->college);
        $contract = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-contract', 'employment_status' => 'state_contract'], self::CHEF, self::TA, $this->college);
        foreach ([$civil, $contract] as $profile) {
            self::assertSame(409, $this->call('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $profile['id'], 'month_ref' => '2026-10', 'gross_amount' => 100000], self::INTENDANT, self::TA, $this->college)[0], $profile['employment_status']);
        }
        $this->ok('PUT', '/secondary/staff/payroll/rules', ['eligible_statuses' => ['private_employee', 'state_contract']], self::CHEF, self::TA, $this->college);
        self::assertSame(201, $this->call('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $contract['id'], 'month_ref' => '2026-10', 'gross_amount' => 100000], self::INTENDANT, self::TA, $this->college)[0], 'Statut retenu par l\'établissement.');
        self::assertSame(409, $this->call('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $civil['id'], 'month_ref' => '2026-10', 'gross_amount' => 100000], self::INTENDANT, self::TA, $this->college)[0], 'Jamais un fonctionnaire.');

        [$status, $problem] = $this->call('GET', '/secondary/staff/payroll/payslips', null, self::INTENDANT, self::TA, $this->leg);
        self::assertSame([409, 'module_not_enabled'], [$status, $problem['code'] ?? null], 'Lycée public : payroll_simple inactif.');
    }

    public function testValidationParUnAutreQueLAuteurEtCloisonnementDesBulletins(): void
    {
        $this->grant(self::TA, 'polyvalent', $this->college, ['secondary.billing.manage', 'secondary.settings.manage']);
        $profile = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-vacataire', 'employment_status' => 'hourly'], self::CHEF, self::TA, $this->college);
        $slip = $this->ok('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $profile['id'], 'month_ref' => '2026-10', 'gross_amount' => 30000, 'deductions' => []], 'polyvalent', self::TA, $this->college);
        self::assertSame(403, $this->call('POST', '/secondary/staff/payroll/payslips/' . $slip['id'] . '/validate', [], 'polyvalent', self::TA, $this->college)[0], 'Celui qui prépare ne valide pas.');
        $schoolB = $this->factory->opened(self::TB, 'college_prive', 'private_secular');
        $this->grant(self::TB, 'intendant-b', $schoolB, ['secondary.billing.manage', 'secondary.finance.read']);
        self::assertSame(404, $this->call('GET', '/secondary/staff/payroll/payslips/' . $slip['id'], null, 'intendant-b', self::TB, $schoolB)[0], 'Bulletin d\'un autre client introuvable.');
        $otherCollege = $this->factory->opened(self::TA, 'lycee_prive', 'private_secular', 'e-lycee-prive-bis');
        $this->grant(self::TA, self::INTENDANT, $otherCollege, ['secondary.billing.manage']);
        self::assertSame(404, $this->call('GET', '/secondary/staff/payroll/payslips/' . $slip['id'], null, self::INTENDANT, self::TA, $otherCollege)[0], 'Bulletin d\'un autre établissement introuvable.');
    }

    public function testHeurePayableSeulementAvecAppelEtSeanceSigneeAuCahier(): void
    {
        $check = $this->service(SecondaryPayabilityCheck::class);
        $hour = fn (?string $declaredBy, ?string $slot, string $school): PayableHour => new PayableHour('hour-1', self::TA, $school, 'staff-civil', 'person-civil', 'permanent', '2026-10-12', 60, 'td', null, null, null, null, $slot, 'Cours fictif', '2026-10-12T10:00:00+01:00', $declaredBy, 'declared');
        self::assertSame([SecondaryPayabilityCheck::NO_ROLL_CALL], $check->hourRefusals($hour('agent-1', 'slot-12', $this->leg), 'censeur'), 'Saisie à la main : non payable.');
        self::assertSame([SecondaryPayabilityCheck::NO_ROLL_CALL], $check->hourRefusals($hour(null, null, $this->leg), 'censeur'));
        self::assertSame([SecondaryPayabilityCheck::NO_SIGNED_LESSON], $check->hourRefusals($hour(null, 'slot-12', $this->leg), 'censeur'), 'Appel sans séance signée au cahier : non payable.');
        $this->lessonLog('log-12', $this->leg, 'person-civil', 'slot-12', '2026-10-12', 1.0);
        self::assertSame([], $check->hourRefusals($hour(null, 'slot-12', $this->leg), 'censeur'), 'Appel validé et séance signée : payable.');
        $this->lessonLog('log-13', $this->leg, 'person-autre', 'slot-13', '2026-10-12', 1.0);
        self::assertSame([SecondaryPayabilityCheck::NO_SIGNED_LESSON], $check->hourRefusals(new PayableHour('hour-2', self::TA, $this->leg, 'staff-civil', 'person-civil', 'permanent', '2026-10-12', 60, 'td', null, null, null, null, 'slot-13', null, null, null, 'declared'), 'censeur'), 'La séance d\'un autre enseignant ne compte pas.');
        self::assertSame([], $check->hourRefusals($hour('agent-1', null, 'e-hors-secondaire'), 'censeur'), 'Établissement d\'une autre application : non concerné.');

        $registered = [];
        foreach ($this->kernel->container()->getKnownEntryNames() as $name) {
            if (str_starts_with($name, VacationPayabilityCheck::REGISTRY_PREFIX . 'secondary')) {
                $registered[] = $name;
            }
        }
        self::assertSame([VacationPayabilityCheck::REGISTRY_PREFIX . 'secondary'], $registered, 'Contrôle déclaré au point d\'extension du socle Staff.');
    }

    public function testCollegeEtLyceePrivesModulesDuPriveEtPaie(): void
    {
        $lycee = $this->factory->opened(self::TA, 'lycee_prive', 'private_faith');
        $this->world->add(self::TA, $lycee, 'person-lycee', 'staff-lycee', 'Joel Lycee');
        foreach (['college_prive' => [$this->college, 'person-salarie'], 'lycee_prive' => [$lycee, 'person-lycee']] as $type => [$school, $person]) {
            $profile = $this->service(EstablishmentProfiles::class)->forEstablishment(self::TA, $school);
            foreach (['fees_private', 'authorizations', 'payroll_simple'] as $module) {
                self::assertTrue($profile->isActive($module), $type . ' : ' . $module);
            }
            self::assertFalse($profile->isActive('fees_public_catalog'), $type . ' : pas de catalogue public');
            $this->grant(self::TA, self::INTENDANT, $school, ['secondary.billing.manage', 'secondary.finance.read', 'secondary.fees.read']);
            $this->grant(self::TA, self::CHEF, $school, ['secondary.settings.manage']);
            [$status, $problem] = $this->call('GET', '/secondary/fees/catalog', null, self::INTENDANT, self::TA, $school);
            self::assertSame([409, 'module_not_enabled'], [$status, $problem['code'] ?? null], $type . ' : garde du catalogue public inactive');
            $staff = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => $person, 'employment_status' => 'private_employee'], self::CHEF, self::TA, $school);
            $slip = $this->ok('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $staff['id'], 'month_ref' => '2026-10', 'gross_amount' => 180000, 'deductions' => [['label' => 'Avance', 'amount' => 30000]]], self::INTENDANT, self::TA, $school);
            self::assertSame(150000, $slip['net_amount'], $type);
        }
    }

    public function testToutLePersonnelEstListeEtFiltreParCategorie(): void
    {
        $this->world->add(self::TA, $this->college, 'person-secretaire', 'staff-secretaire', 'Berthe Secretaire', 'permanent', 'active', false, 'Secrétaire', 'administratif');
        $this->world->add(self::TA, $this->college, 'person-agent', 'staff-agent', 'Remy Agent', 'vacataire', 'active', false, 'Agent d’entretien', 'entretien');
        $all = $this->ok('GET', '/secondary/staff/profiles', null, self::CENSEUR, self::TA, $this->college)['items'];
        $byPerson = array_column($all, null, 'person_id');
        self::assertArrayHasKey('person-secretaire', $byPerson, 'Le personnel non enseignant est listé.');
        self::assertSame(['Secrétaire', false, null], [$byPerson['person-secretaire']['function'], $byPerson['person-secretaire']['teaches'], $byPerson['person-secretaire']['staff_category']]);
        self::assertSame('teaching', $byPerson['person-salarie']['staff_category'], 'Catégorie de départ : enseignant si le socle connaît une affectation.');
        $saved = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-agent', 'employment_status' => 'hourly', 'staff_category' => 'maintenance'], self::CHEF, self::TA, $this->college);
        self::assertSame(['maintenance', 'hourly', 'Agent d’entretien'], [$saved['staff_category'], $saved['employment_status'], $saved['function']]);
        self::assertSame(['person-agent'], array_column($this->ok('GET', '/secondary/staff/profiles?category=maintenance', null, self::CENSEUR, self::TA, $this->college)['items'], 'person_id'));
        self::assertSame(422, $this->call('GET', '/secondary/staff/profiles?category=cuisine', null, self::CENSEUR, self::TA, $this->college)[0]);
        self::assertNotContains('person-agent', array_column($this->ok('GET', '/secondary/staff/hours?month=2026-10', null, self::CENSEUR, self::TA, $this->college)['items'], 'person_id'), 'Heures de l\'appel : enseignants seulement.');
        self::assertSame(404, $this->call('PUT', '/secondary/staff/profiles', ['person_id' => 'person-agent', 'employment_status' => 'hourly'], self::CHEF, self::TA, $this->leg)[0], 'Autre établissement.');
    }

    public function testPaieDuPersonnelNonEnseignantAvecHeuresMensuellesValideesParLeChef(): void
    {
        $this->world->add(self::TA, $this->college, 'person-agent', 'staff-agent', 'Remy Agent', 'vacataire', 'active', false, 'Agent d’entretien', 'entretien');
        $this->world->add(self::TA, $this->college, 'person-civil-adm', 'staff-civil-adm', 'Ines Fonctionnaire', 'permanent', 'active', false, 'Surveillante générale', 'administratif');
        $agent = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-agent', 'employment_status' => 'hourly', 'staff_category' => 'maintenance'], self::CHEF, self::TA, $this->college);
        $teacher = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-vacataire', 'employment_status' => 'hourly'], self::CHEF, self::TA, $this->college);
        $slip = ['staff_profile_id' => $agent['id'], 'month_ref' => '2026-10', 'gross_amount' => 40000, 'deductions' => []];
        self::assertSame(409, $this->call('POST', '/secondary/staff/payroll/payslips', $slip, self::INTENDANT, self::TA, $this->college)[0], 'Vacataire non enseignant : heures du mois validées exigées.');
        self::assertSame(409, $this->call('PUT', '/secondary/staff/payroll/hours', ['staff_profile_id' => $teacher['id'], 'month_ref' => '2026-10', 'minutes' => 600], self::INTENDANT, self::TA, $this->college)[0], 'Enseignant : heures de l\'appel, pas de saisie mensuelle.');
        self::assertSame(403, $this->call('PUT', '/secondary/staff/payroll/hours', ['staff_profile_id' => $agent['id'], 'month_ref' => '2026-10', 'minutes' => 4800], self::CENSEUR, self::TA, $this->college)[0]);
        $hours = $this->ok('PUT', '/secondary/staff/payroll/hours', ['staff_profile_id' => $agent['id'], 'month_ref' => '2026-10', 'minutes' => 4800, 'note' => 'Entretien fictif'], self::INTENDANT, self::TA, $this->college);
        self::assertSame(['declared', 4800], [$hours['status'], $hours['minutes']]);
        self::assertSame(403, $this->call('POST', '/secondary/staff/payroll/hours/' . $hours['id'] . '/validate', [], self::INTENDANT, self::TA, $this->college)[0], 'Le chef valide, pas l\'intendant.');
        self::assertSame('validated', $this->ok('POST', '/secondary/staff/payroll/hours/' . $hours['id'] . '/validate', [], self::CHEF, self::TA, $this->college)['status']);
        self::assertSame(409, $this->call('PUT', '/secondary/staff/payroll/hours', ['staff_profile_id' => $agent['id'], 'month_ref' => '2026-10', 'minutes' => 10], self::INTENDANT, self::TA, $this->college)[0], 'Heures validées : plus de ressaisie.');
        self::assertSame([4800], array_column($this->ok('GET', '/secondary/staff/payroll/hours?month=2026-10', null, self::CHEF, self::TA, $this->college)['items'], 'minutes'));
        self::assertSame(40000, $this->ok('POST', '/secondary/staff/payroll/payslips', $slip, self::INTENDANT, self::TA, $this->college)['net_amount']);
        $civil = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-civil-adm', 'employment_status' => 'civil_servant', 'staff_category' => 'administrative'], self::CHEF, self::TA, $this->college);
        self::assertSame(409, $this->call('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $civil['id'], 'month_ref' => '2026-10', 'gross_amount' => 1], self::INTENDANT, self::TA, $this->college)[0], 'Fonctionnaire non enseignant : jamais éligible.');
    }

    public function testPdfDuBulletinDePaieDroitsEtablissementEtRendu(): void
    {
        $profile = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-salarie', 'employment_status' => 'private_employee'], self::CHEF, self::TA, $this->college);
        $slip = $this->ok('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $profile['id'], 'month_ref' => '2026-10', 'gross_amount' => 100000], self::INTENDANT, self::TA, $this->college);
        self::assertSame(404, $this->call('GET', '/secondary/payroll/' . $slip['id'] . '/pdf', null, self::INTENDANT, self::TA, $this->college)[0], 'Pas encore payé : aucun DOC-30.');
        $this->ok('POST', '/secondary/staff/payroll/payslips/' . $slip['id'] . '/validate', [], self::CHEF, self::TA, $this->college);
        $this->ok('POST', '/secondary/staff/payroll/payslips/' . $slip['id'] . '/pay', [], self::INTENDANT, self::TA, $this->college);

        foreach ([self::INTENDANT, self::CHEF] as $user) {
            $response = $this->raw('GET', '/secondary/payroll/' . $slip['id'] . '/pdf', null, $user, self::TA, $this->college);
            self::assertSame(200, $response->getStatusCode(), $user);
            self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            self::assertStringStartsWith('%PDF', (string) $response->getBody());
        }
        $this->grant(self::TA, 'lecteur-finances', $this->college, ['secondary.finance.read']);
        self::assertSame(403, $this->call('GET', '/secondary/payroll/' . $slip['id'] . '/pdf', null, 'lecteur-finances', self::TA, $this->college)[0], 'Droit de la paie exigé.');
        self::assertSame(403, $this->call('GET', '/secondary/payroll/' . $slip['id'] . '/pdf', null, self::CENSEUR, self::TA, $this->college)[0]);
        $other = $this->factory->opened(self::TA, 'lycee_prive', 'private_secular', 'e-lycee-prive-pdf');
        $this->grant(self::TA, self::INTENDANT, $other, ['secondary.billing.manage']);
        self::assertSame(404, $this->call('GET', '/secondary/payroll/' . $slip['id'] . '/pdf', null, self::INTENDANT, self::TA, $other)[0], 'Bulletin d\'un autre établissement.');
        $schoolB = $this->factory->opened(self::TB, 'college_prive', 'private_secular');
        $this->grant(self::TB, 'intendant-b', $schoolB, ['secondary.billing.manage']);
        self::assertSame(404, $this->call('GET', '/secondary/payroll/' . $slip['id'] . '/pdf', null, 'intendant-b', self::TB, $schoolB)[0], 'Autre client.');
    }

    /**
     * FIX-SEC, défaut 8 : l'administrateur du client (super_admin : staff.read, staff.manage du socle, aucun droit secondary.*)
     * recevait « Accès refusé » sur Personnel et Paie. Il lit et tient les profils, lit la paie ; préparer et valider restent à
     * l'intendant et au chef d'établissement.
     */
    public function testLAdministrateurDuClientOuvrePersonnelEtPaie(): void
    {
        $admin = 'admin-du-client';
        $this->grant(self::TA, $admin, $this->college, ['staff.read', 'staff.manage', 'vacations.validate']);
        self::assertNotSame([], $this->ok('GET', '/secondary/staff/profiles', null, $admin, self::TA, $this->college)['items']);
        self::assertIsArray($this->ok('GET', '/secondary/staff/hours?month=2026-10', null, $admin, self::TA, $this->college)['items']);
        $profile = $this->ok('PUT', '/secondary/staff/profiles', ['person_id' => 'person-salarie', 'employment_status' => 'private_employee'], $admin, self::TA, $this->college);
        self::assertSame('private_employee', $profile['employment_status']);
        self::assertSame([], $this->ok('GET', '/secondary/staff/payroll/payslips', null, $admin, self::TA, $this->college)['items']);
        self::assertNotSame([], $this->ok('GET', '/secondary/staff/payroll/rules', null, $admin, self::TA, $this->college)['eligible_statuses']);
        [$status] = $this->call('POST', '/secondary/staff/payroll/payslips', ['staff_profile_id' => $profile['id'], 'month_ref' => '2026-10', 'gross_amount' => 1000], $admin, self::TA, $this->college);
        self::assertSame(403, $status, 'Préparer un bulletin reste à l\'intendant.');
    }

    private function lessonLog(string $id, string $school, string $teacher, string $slot, string $date, float $hours): void
    {
        $this->service(Store::class)->insert('se_lesson_logs', self::TA, $school, [
            'id' => str_pad(strtoupper(str_replace('-', '', $id)), 26, '0'), 'academic_year_id' => 'year-fake', 'structure_id' => 'structure-fake',
            'assignment_id' => '01J00000000000000000000002', 'se_subject_id' => '01J00000000000000000000003', 'subject_id' => 'subject-fake',
            'subject_label' => 'Matière fictive', 'teacher_person_id' => $teacher, 'entry_id' => null, 'slot_id' => $slot, 'session_date' => $date,
            'lesson_id' => null, 'content' => 'Leçon fictive signée', 'homework' => null, 'hours' => $hours, 'signed_by' => 'user-' . $teacher,
            'signed_at' => $date . 'T10:00:00+01:00', 'signature_hash' => str_repeat('a', 64), 'client_uuid' => 'uuid-' . $id, 'created_at' => $date . 'T10:00:00+01:00',
        ]);
    }
}
