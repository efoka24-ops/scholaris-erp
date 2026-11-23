<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Enrolment;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\HigherPromotionPlanner;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\MatriculeService;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentNumberRequest;
use Tests\Support\Higher\Admissions\EntryTestCase;

/**
 * INS : inscription depuis la candidature, matricule 26EC-CF-0042 (RG-INS-1), validation et réserve financière (RG-INS-2),
 * réinscription (RG-INS-3/4), limite d'inscriptions dans un niveau (RG-INS-5), abandon (RG-INS-6), consentement (RG-INS-7),
 * double cursus (RG-ORG-6), carte d'étudiant (DOC-05), passage de fin d'année (D8), isolation.
 */
final class EnrolmentTest extends EntryTestCase
{
    private const NEXT = 'AY-2027';
    private const AFTER = 'AY-2028';

    protected function setUp(): void
    {
        $this->bootEntry();
    }

    public function testMatricule26ECCF0042AttribueALaValidationDefinitifEtNot04(): void
    {
        $this->seedSequence('26EC-CF-#', 41);
        $enrolment = $this->enrolAdmitted('Matricule');
        self::assertSame(['pending', null, 1, $this->curriculum['id']], [$enrolment['status'], $enrolment['matricule'], $enrolment['attempt_number'], $enrolment['curriculum_id']]);

        [$status, $problem] = $this->call('POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [], self::SCOL);
        self::assertSame(422, $status, 'Frais d\'inscription non payés (RG-INS-2).');
        self::assertStringContainsString('RG-INS-2', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));

        $this->registrationAccount((string) $enrolment['person_id'], self::YEAR, $this->org['program']);
        $validated = $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [], self::SCOL);
        self::assertSame(['enrolled', '26EC-CF-0042'], [$validated['status'], $validated['matricule']]);
        self::assertMatchesRegularExpression('/^\d{2}[A-Z]+-[A-Z]+-\d{4}$/', (string) $validated['matricule']);
        $not04 = array_values(array_filter($this->fakes->notifications, static fn (array $n): bool => $n['code'] === 'NOT-04'));
        self::assertSame('26EC-CF-0042', $not04[0]['variables']['reference']);

        $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/status', ['status' => 'withdrawn', 'reason' => 'Abandon.'], self::SCOL);
        $next = $this->enrolAdmitted('Suivant');
        $this->registrationAccount((string) $next['person_id'], self::YEAR, $this->org['program']);
        self::assertSame('26EC-CF-0043', $this->ok('POST', '/higher/enrolments/' . $next['id'] . '/validate', [], self::SCOL)['matricule'], 'Jamais réattribué.');
        self::assertSame('26EC-CF-0042', $this->service(StudentDirectory::class)->student(self::T, self::E, (string) $enrolment['person_id'])?->matricule);
    }

    public function testReserveFinanciereSeulementSurDecisionDuDirecteurDEcole(): void
    {
        $enrolment = $this->enrolAdmitted('Reserve');
        [$status] = $this->call('POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', ['financial_hold' => true, 'reason' => 'Bourse attendue.'], self::SCOL);
        self::assertSame(403, $status, 'La scolarité ne peut pas inscrire sous réserve financière.');

        $held = $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', ['financial_hold' => true, 'reason' => 'Bourse attendue.'], self::DIR);
        self::assertSame(['financial_hold', true], [$held['status'], $held['financial_hold']]);
        self::assertNotNull($held['matricule']);

        $this->registrationAccount((string) $enrolment['person_id'], self::YEAR, $this->org['program']);
        $lifted = $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [], self::SCOL);
        self::assertSame(['enrolled', false, $held['matricule']], [$lifted['status'], $lifted['financial_hold'], $lifted['matricule']]);
    }

    public function testReinscriptionExigeLaDecisionDePassageEtInscritLesDettes(): void
    {
        $this->tutelage($this->org['program'], self::NEXT);
        $enrolment = $this->validated($this->enrolAdmitted('Dettes'));
        $person = (string) $enrolment['person_id'];

        [$status, $problem] = $this->call('POST', '/higher/enrolments', ['person_id' => $person, 'level_id' => $this->org['l2'], 'academic_year_id' => self::NEXT], self::SCOL);
        self::assertSame(422, $status);
        self::assertStringContainsString('RG-INS-3', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));

        $this->decide($person, $this->org['l1'], self::YEAR, 'repeat', ['UE1'], ['UE2', 'UE3', 'UE4', 'UE5']);
        [$status] = $this->call('POST', '/higher/enrolments', ['person_id' => $person, 'level_id' => $this->org['l2'], 'academic_year_id' => self::NEXT], self::SCOL);
        self::assertSame(422, $status, '« Redouble » n\'autorise pas le niveau suivant.');

        $this->decide($person, $this->org['l1'], self::YEAR, 'admitted_with_debts', ['UE1', 'UE3', 'UE4', 'UE5'], ['UE2']);
        $l2 = $this->ok('POST', '/higher/enrolments', ['person_id' => $person, 'level_id' => $this->org['l2'], 'academic_year_id' => self::NEXT], self::SCOL);
        $contract = $this->ok('GET', '/higher/enrolments/' . $l2['id'] . '/learning-contract', null, self::SCOL);
        $debts = array_values(array_filter($contract['items'], static fn (array $i): bool => $i['origin'] === 'debt'));
        self::assertSame([['UE2', 'Unité UE2', 6, $this->org['l1']]], array_map(static fn (array $i): array => [$i['unit_code'], $i['unit_title'], $i['credits'], $i['source_level_id']], $debts));
        self::assertSame(6, $contract['credits_by_origin']['debt']);
    }

    public function testRedoublementCapitaliseLesUeAcquises(): void
    {
        $this->tutelage($this->org['program'], self::NEXT);
        $next = $this->marie(self::NEXT);
        $this->ok('POST', '/higher/curricula/' . $next['id'] . '/publish');
        $person = (string) $this->validated($this->enrolAdmitted('Capital'))['person_id'];
        $this->decide($person, $this->org['l1'], self::YEAR, 'repeat', ['UE1', 'UE3'], ['UE2', 'UE4', 'UE5']);

        $again = $this->ok('POST', '/higher/enrolments', ['person_id' => $person, 'level_id' => $this->org['l1'], 'academic_year_id' => self::NEXT], self::SCOL);
        self::assertSame([2, $next['id']], [$again['attempt_number'], $again['curriculum_id']]);
        $contract = $this->ok('GET', '/higher/enrolments/' . $again['id'] . '/learning-contract', null, self::SCOL);
        self::assertSame(['capitalized' => 12, 'normal' => 18], ['capitalized' => $contract['credits_by_origin']['capitalized'], 'normal' => $contract['credits_by_origin']['normal']]);
        self::assertSame(18, $contract['credits_to_take']);
    }

    public function testTroisiemeInscriptionDansLeNiveauRefuseeSaufDerogation(): void
    {
        $this->tutelage($this->org['program'], self::NEXT);
        $this->tutelage($this->org['program'], self::AFTER);
        $person = (string) $this->validated($this->enrolAdmitted('Triple'))['person_id'];
        $this->decide($person, $this->org['l1'], self::YEAR, 'repeat', [], ['UE1']);
        $second = $this->ok('POST', '/higher/enrolments', ['person_id' => $person, 'level_id' => $this->org['l1'], 'academic_year_id' => self::NEXT], self::SCOL);
        $this->registrationAccount($person, self::NEXT, $this->org['program']);
        $this->ok('POST', '/higher/enrolments/' . $second['id'] . '/validate', [], self::SCOL);
        $this->decide($person, $this->org['l1'], self::NEXT, 'repeat', [], ['UE1']);

        [$status, $problem] = $this->call('POST', '/higher/enrolments', ['person_id' => $person, 'level_id' => $this->org['l1'], 'academic_year_id' => self::AFTER], self::SCOL);
        self::assertSame(422, $status);
        self::assertStringContainsString('3e inscription', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('RG-INS-5', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));

        [$status] = $this->call('POST', '/higher/enrolment-derogations', ['person_id' => $person, 'level_id' => $this->org['l1'], 'academic_year_id' => self::AFTER, 'reason' => 'x'], self::SCOL);
        self::assertSame(403, $status, 'Seul le directeur d\'école déroge.');
        $this->ok('POST', '/higher/enrolment-derogations', ['person_id' => $person, 'level_id' => $this->org['l1'], 'academic_year_id' => self::AFTER, 'reason' => 'Maladie attestée.'], self::DIR);
        $third = $this->ok('POST', '/higher/enrolments', ['person_id' => $person, 'level_id' => $this->org['l1'], 'academic_year_id' => self::AFTER], self::SCOL);
        self::assertSame(3, $third['attempt_number']);
        self::assertNotNull($third['derogation_id']);
    }

    public function testDoubleCursusUnCompteUnMatriculeDeuxParcours(): void
    {
        $person = (string) $this->validated($this->enrolAdmitted('Double'))['person_id'];
        $gl = $this->secondProgram();
        $application = $this->completeApplication($this->openCampaign(10, 10000, $gl), 'Double', 'bac', $person);
        $this->admit((string) $application['id']);

        [$status, $problem] = $this->call('POST', '/higher/enrolments', ['application_id' => $application['id']], self::SCOL);
        self::assertSame(409, $status, 'Double cursus non autorisé par défaut.');
        self::assertStringContainsString('enrolment.allow_multiple_tracks', (string) $problem['detail']);

        $this->settings->set(self::E, 'enrolment.allow_multiple_tracks', true);
        $second = $this->ok('POST', '/higher/enrolments', ['application_id' => $application['id']], self::SCOL);
        $this->registrationAccount($person, self::YEAR, (string) $second['program_id']);
        $validated = $this->ok('POST', '/higher/enrolments/' . $second['id'] . '/validate', [], self::SCOL);

        $file = $this->ok('GET', '/higher/students/' . $person, null, self::SCOL);
        self::assertCount(2, $file['tracks']);
        self::assertSame($file['matricule'], $validated['matricule'], 'Un seul matricule.');
        self::assertStringStartsWith('26EC-CF-', (string) $file['matricule']);
        self::assertSame(1, $this->countRows('hi_students', $person));
        self::assertCount(2, $this->service(StudentDirectory::class)->enrolmentsOf(self::T, self::E, $person));
    }

    public function testConsentementDeLEtudiantAuPartageAvecLeGarant(): void
    {
        $enrolment = $this->enrolAdmitted('Consent', true);
        $person = (string) $enrolment['person_id'];
        self::assertFalse($this->service(StudentDirectory::class)->sharesWithGuarantor(self::T, self::E, $person));

        $this->links->link(self::T, 'etudiant', $person);
        $this->access->grant('etudiant', self::E);
        $this->access->grant('inconnu', self::E);
        $shared = $this->ok('PUT', '/higher/students/' . $person . '/guarantor-consent', ['share_with_guarantor' => true], 'etudiant');
        self::assertTrue($shared['share_with_guarantor']);
        self::assertTrue($this->service(StudentDirectory::class)->sharesWithGuarantor(self::T, self::E, $person));
        [$status] = $this->call('PUT', '/higher/students/' . $person . '/guarantor-consent', ['share_with_guarantor' => false], 'inconnu');
        self::assertSame(403, $status);
        $this->ok('PUT', '/higher/students/' . $person . '/guarantor-consent', ['share_with_guarantor' => false], 'etudiant');
        self::assertFalse($this->service(StudentDirectory::class)->sharesWithGuarantor(self::T, self::E, $person));
        self::assertContains('higher.student.guarantor_consent', array_column($this->audit->entries, 'action'));
    }

    public function testCarteDEtudiantAvecQrEtFicheDInscription(): void
    {
        $enrolment = $this->validated($this->enrolAdmitted('Carte'));
        $card = $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/student-card', null, self::SCOL);
        $form = $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/enrolment-form', null, self::SCOL);

        self::assertSame(['DOC-05', 'DOC-04'], [$card['code'], $form['code']]);
        $issued = array_slice($this->fakes->documents, -2);
        self::assertSame($enrolment['matricule'], $issued[0]['data']['holder_matricule']);
        self::assertStringContainsString('UE1', (string) $issued[1]['data']['units_list']);
        self::assertSame($card['id'], $this->ok('GET', '/higher/enrolments/' . $enrolment['id'], null, self::SCOL)['card_document_id']);
    }

    public function testAbandonRembourseSelonLaGrilleAvantLaDateLimite(): void
    {
        $this->settings->set(self::E, 'higher.enrolment.withdrawal_deadline', '2026-11-30');
        $this->settings->set(self::E, 'higher.enrolment.refund_grid', [['until' => '2026-10-31', 'percent' => 50], ['until' => '2026-11-30', 'percent' => 25]]);
        $enrolment = $this->enrolAdmitted('Abandon');
        $this->registrationAccount((string) $enrolment['person_id'], self::YEAR, $this->org['program'], true, 120000);
        $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [], self::SCOL);
        $withdrawn = $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/status', ['status' => 'withdrawn', 'reason' => 'Départ.'], self::SCOL);

        self::assertSame(['withdrawn', 50, 60000], [$withdrawn['status'], $withdrawn['refund_percent'], $withdrawn['refund_xaf']]);
    }

    public function testChangementDeFiliereGardeLeMatricule(): void
    {
        $enrolment = $this->validated($this->enrolAdmitted('Change'));
        $gl = $this->secondProgram();
        $changed = $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/change', ['level_id' => $gl, 'reason' => 'Réorientation.'], self::SCOL);

        self::assertSame([$enrolment['matricule'], true], [$changed['matricule'], $changed['fees_recalculation_required']]);
        self::assertNotSame($enrolment['program_id'], $changed['program_id']);
        self::assertContains('change_program', array_column($this->ok('GET', '/higher/enrolments/' . $enrolment['id'] . '/events', null, self::SCOL), 'event_type'));
    }

    public function testFournisseurDeNumeroDuSocleRendLeMatriculeIpes(): void
    {
        $enrolment = $this->validated($this->enrolAdmitted('Socle'));
        $provider = $this->service(MatriculeService::class);
        $request = new EnrollmentNumberRequest(self::T, self::E, 'higher', 'enr-1', (string) $enrolment['person_id'], $this->org['l1'], self::YEAR, 2026);

        self::assertTrue($provider->supports($request));
        self::assertSame($enrolment['matricule'], $provider->next($request));
        self::assertFalse($provider->supports(new EnrollmentNumberRequest(self::T, self::E, 'secondary', 'enr-2', 'x', $this->org['l1'], self::YEAR, 2026)));
    }

    public function testPassageDeFinDAnneeCreeLesReinscriptionsEnAttente(): void
    {
        $this->tutelage($this->org['program'], self::NEXT);
        $admitted = (string) $this->validated($this->enrolAdmitted('Passe'))['person_id'];
        $excluded = (string) $this->validated($this->enrolAdmitted('Exclu'))['person_id'];
        $this->decide($admitted, $this->org['l1'], self::YEAR, 'admitted', ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], []);
        $this->decide($excluded, $this->org['l1'], self::YEAR, 'excluded', [], []);

        $planner = $this->service(HigherPromotionPlanner::class);
        self::assertSame(['higher'], $planner->establishmentTypes());
        self::assertSame(['created' => 1, 'skipped' => 1], $planner->promote(self::T, self::E, self::YEAR, self::NEXT)->counts);
        self::assertSame(['created' => 0, 'skipped' => 2], $planner->promote(self::T, self::E, self::YEAR, self::NEXT)->counts, 'Idempotent.');
        $created = $this->ok('GET', '/higher/enrolments?filter[person_id]=' . $admitted . '&filter[academic_year_id]=' . self::NEXT, null, self::SCOL);
        self::assertSame([[$this->org['l2'], 'pending', 'reenrolment']], array_map(static fn (array $e): array => [$e['level_id'], $e['status'], $e['kind']], $created));
    }

    public function testIsolationClientEtPerimetre(): void
    {
        $enrolment = $this->enrolAdmitted('Isolation');
        $this->assign(self::TB, self::ADMIN_B, self::EB, 'higher.enrolment.read', 'establishment');
        [$tenant] = $this->call('GET', '/higher/enrolments/' . $enrolment['id'], null, self::ADMIN_B, self::TB, self::EB);
        $this->assign(self::T, 'scol-g', self::E, 'higher.enrol', 'class', $this->org['g1'], 'higher_scolarite');
        [$read] = $this->call('GET', '/higher/enrolments/' . $enrolment['id'], null, 'scol-g');
        [$write] = $this->call('POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [], 'scol-g');

        self::assertSame([404, 404, 403], [$tenant, $read, $write]);
        self::assertSame([], $this->ok('GET', '/higher/enrolments', null, self::ADMIN_B, self::TB, self::EB));
    }

    /** @return array<string, mixed> inscription en attente d'un candidat admis (fiche créée au guichet) */
    private function enrolAdmitted(string $name, bool $inGroup = false): array
    {
        $campaign = $this->campaignId ??= $this->openCampaign(100);
        $application = $this->completeApplication($campaign, $name);
        $this->admit((string) $application['id']);
        $enrolment = $this->ok('POST', '/higher/enrolments', ['application_id' => $application['id']] + ($inGroup ? ['group_id' => $this->org['g1']] : []), self::SCOL);
        self::assertSame('enrolled', $this->ok('GET', '/higher/admissions/applications/' . $application['id'], null, self::SCOL)['status']);

        return $enrolment;
    }

    private ?string $campaignId = null;

    /**
     * @param array<string, mixed> $enrolment
     * @return array<string, mixed>
     */
    private function validated(array $enrolment): array
    {
        $this->registrationAccount((string) $enrolment['person_id'], (string) $enrolment['academic_year_id'], (string) $enrolment['program_id']);

        return $this->ok('POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [], self::SCOL);
    }

    /**
     * @param list<string> $acquired
     * @param list<string> $missing
     */
    private function decide(string $person, string $level, string $year, string $decision, array $acquired, array $missing): void
    {
        $this->ok('POST', '/higher/progression-decisions', [
            'person_id' => $person, 'level_id' => $level, 'academic_year_id' => $year, 'decision' => $decision, 'acquired_units' => $acquired, 'missing_units' => $missing,
        ], self::DIR);
    }

    /** Seconde filière (GL, niveau 1) de l'école EC, autorisée, avec tutelle de l'année : renvoie son niveau 1. */
    private function secondProgram(): string
    {
        $department = (string) $this->ok('POST', '/higher/departments', ['school_id' => $this->org['school'], 'code' => 'DGL', 'name' => 'Gestion'])['id'];
        $program = (string) $this->ok('POST', '/higher/programs', [
            'department_id' => $department, 'code' => 'GL', 'name' => 'Gestion logistique', 'regime' => 'lmd', 'teaching_language' => 'fr', 'study_mode' => 'day', 'duration_years' => 3,
        ])['id'];
        $level = (string) $this->ok('POST', '/higher/levels', ['program_id' => $program, 'ordinal' => 1, 'code' => 'L1', 'name' => 'Licence 1'])['id'];
        $this->authorize($program, null, '2025-09-01', '2029-08-31', 'ARR-2025-0151');
        $this->tutelage($program);

        return $level;
    }

    private function seedSequence(string $key, int $last): void
    {
        $this->service(ConnectionFactory::class)->pdo()->prepare(
            'INSERT INTO hi_matricule_sequences (id, tenant_id, establishment_id, sequence_key, last_number, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        )->execute(['01SEQ000000000000000000001', self::T, self::E, $key, $last, '2026-10-01T08:00:00+01:00']);
    }

    private function countRows(string $table, string $person): int
    {
        $statement = $this->service(ConnectionFactory::class)->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE tenant_id = ? AND establishment_id = ? AND person_id = ?');
        $statement->execute([self::T, self::E, $person]);

        return (int) $statement->fetchColumn();
    }
}
