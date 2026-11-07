<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Enrolment;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\StudentFactsReader;
use Scholaris\Modules\Apps\Secondary\Enrolment\Application\EnrolmentService;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\StudentRegistry;
use Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure\PdoStudentRegistry;
use Scholaris\Modules\Apps\Secondary\Notifications\Contracts\FamilyNotifications;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationData;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T823 à T827 : inscription (API-01), matricule (API-02, RG-INS-1), garde du secondaire (RG-ORG-1, RG-ORG-3), âge (RG-INS-2),
 * exclu définitif (RG-INS-3), aucun refus pour impayé (RG-INS-4), préinscription et fichier du primaire, réinscription,
 * transferts, import des élèves (CSV). Données fictives.
 */
final class EnrolmentTest extends SecondaryTestCase
{
    private const YEAR = 'annee-2026';
    private const NEXT = 'annee-2027';
    private const SETUP = ['secondary.profile.manage', 'secondary.settings.manage'];
    private const DESK = ['secondary.enrol.manage', 'secondary.enrol.read'];

    /** @var list<string> */
    private array $admitted = [];

    /** @var list<string> inscriptions notifiées (NOT-01) */
    public array $notified = [];

    protected function setUp(): void
    {
        $test = $this;
        $notifications = new class ($test) implements FamilyNotifications {
            public function __construct(private readonly EnrolmentTest $test)
            {
            }

            public function enrolmentValidated(string $tenantId, string $establishmentId, string $enrollmentId): int
            {
                $this->test->notified[] = $enrollmentId;

                return 1;
            }

            public function paymentReceived(string $tenantId, string $establishmentId, string $learnerId, string $receiptNumber, int $amountXaf): int
            {
                return 0;
            }
        };
        $this->bootSecondary([FamilyNotifications::class => $notifications, AdmissionDirectory::class => new class ($test) implements AdmissionDirectory {
            public function __construct(private readonly EnrolmentTest $test)
            {
            }

            public function admittedPersonIds(string $tenantId, string $establishmentId, string $academicYearId): array
            {
                return $this->test->admittedList();
            }
        }]);
    }

    /** @return list<string> */
    public function admittedList(): array
    {
        return $this->admitted;
    }

    /**
     * Établissement ouvert avec niveaux d'exemple, autorisation valide et une classe de 6e (et de 5e pour l'année suivante).
     *
     * @return array{school: string, sixth: string, fifth: string, levels: array<string, array<string, mixed>>}
     */
    private function school(string $code = 'ces', ?string $ownership = null): array
    {
        $school = $this->factory->opened(self::TA, $code, $ownership);
        $this->grant(self::TA, self::ADMIN, $school, [...self::SETUP, ...self::DESK, 'secondary.enrol.validate']);
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $school), null, 'code');
        foreach ($levels as $level) {
            $this->service(AuthorizationRegistry::class)->register(self::TA, $school, new AuthorizationData((string) $level['structure_id'], 'AUT-' . $level['code'], '2026-01-01', '2030-12-31', 'MINESEC'), 'test');
        }
        $sixth = $this->ok('POST', '/secondary/setup/classes', ['label' => '6e A', 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $school);
        $fifth = $this->ok('POST', '/secondary/setup/classes', ['label' => '5e A', 'level_id' => $levels['5E']['id'], 'academic_year_id' => self::NEXT], self::ADMIN, self::TA, $school);

        return ['school' => $school, 'sixth' => (string) $sixth['id'], 'fifth' => (string) $fifth['id'], 'levels' => $levels];
    }

    /** @return array<string, mixed> */
    private static function pupil(string $first, string $class, array $extra = []): array
    {
        return $extra + [
            'identity' => ['last_name' => 'NGONO', 'first_name' => $first, 'birth_date' => '2014-03-12', 'gender' => 'female'],
            'guardians' => [['kind' => 'parent', 'identity' => ['last_name' => 'NGONO', 'first_name' => 'Paul', 'phone' => '+237600000000']]],
            'class_id' => $class, 'entry_date' => '2026-09-07',
        ];
    }

    public function testInscriptionMatriculeEnAttentePuisEnregistre(): void
    {
        $s = $this->school();
        $dossier = $this->ok('POST', '/secondary/enrolments', self::pupil('Aïcha', $s['sixth'], ['documents_checked' => ['acte de naissance']]), self::ADMIN, self::TA, $s['school']);
        self::assertSame('pending', $dossier['national_id_status'], 'RG-INS-1 : sans matricule, en attente.');
        self::assertSame([], $this->notified, 'NOT-01 seulement quand le matricule est enregistré.');
        self::assertNull($this->service(StudentFactsReader::class)->nationalId(self::TA, $s['school'], $dossier['person_id']));
        self::assertCount(1, $dossier['enrollments']);
        $pending = $this->ok('GET', '/secondary/enrolment/students?pending=1&academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $s['school']);
        self::assertSame([$dossier['person_id']], array_column($pending, 'person_id'));
        self::assertSame(422, $this->call('PUT', '/secondary/students/' . $dossier['person_id'] . '/national-id', ['national_id' => '!'], self::ADMIN, self::TA, $s['school'])[0]);
        $after = $this->ok('PUT', '/secondary/students/' . $dossier['person_id'] . '/national-id', ['national_id' => 'cm2026-000123'], self::ADMIN, self::TA, $s['school']);
        self::assertSame('recorded', $after['national_id_status']);
        self::assertSame('CM2026-000123', $after['national_id']);
        self::assertSame([$after['records'][0]['enrollment_id']], $this->notified, 'NOT-01 : inscription validée et matricule enregistré.');
        self::assertSame('CM2026-000123', $this->service(StudentFactsReader::class)->nationalId(self::TA, $s['school'], $dossier['person_id']), 'StudentFacts fourni par Enrolment.');
        self::assertSame([], $this->ok('GET', '/secondary/enrolment/students?pending=1', null, self::ADMIN, self::TA, $s['school']));
        $other = $this->ok('POST', '/secondary/enrolments', self::pupil('Brice', $s['sixth']), self::ADMIN, self::TA, $s['school']);
        self::assertSame(409, $this->call('PUT', '/secondary/students/' . $other['person_id'] . '/national-id', ['national_id' => 'CM2026-000123'], self::ADMIN, self::TA, $s['school'])[0], 'Matricule unique.');
        self::assertSame(409, $this->call('POST', '/secondary/enrolments', self::pupil('Carine', $s['sixth'], ['national_id' => 'CM2026-000123']), self::ADMIN, self::TA, $s['school'])[0], 'Matricule déjà attribué.');
    }

    public function testGardeCycleAutorisationEtAucunRefusPourImpaye(): void
    {
        $s = $this->school('college_prive', 'private_secular');
        $level = $s['levels']['6E'];
        foreach ($this->service(AuthorizationRegistry::class)->forStructure(self::TA, $s['school'], (string) $level['structure_id']) as $authorization) {
            $this->service(AuthorizationRegistry::class)->remove(self::TA, $s['school'], $authorization->id, 'test');
        }
        [$status, $problem] = $this->call('POST', '/secondary/enrolments', self::pupil('Dora', $s['sixth']), self::ADMIN, self::TA, $s['school']);
        self::assertSame(422, $status, 'RG-ORG-3 : sans autorisation valide, refus.');
        self::assertStringContainsString('RG-ORG-3', json_encode($problem, JSON_UNESCAPED_UNICODE) ?: '');

        $ces = $this->school('ces');
        $this->pdo()->prepare('UPDATE se_class_profiles SET cycle_code = ? WHERE id = ?')->execute(['second', $ces['sixth']]);
        [$status, $problem] = $this->call('POST', '/secondary/enrolments', self::pupil('Eva', $ces['sixth']), self::ADMIN, self::TA, $ces['school']);
        self::assertSame(422, $status, 'RG-ORG-1 : classe d\'un cycle absent du profil.');
        self::assertStringContainsString('RG-ORG-1', json_encode($problem, JSON_UNESCAPED_UNICODE) ?: '');

        $public = $this->school('leg');
        self::assertSame(201, $this->call('POST', '/secondary/enrolments', self::pupil('Fanta', $public['sixth']), self::ADMIN, self::TA, $public['school'])[0], 'RG-INS-4 : aucune facture ni paiement exigé.');
    }

    public function testAgeHorsLimitesExigeLaDecisionDuChef(): void
    {
        $s = $this->school();
        $this->ok('PUT', '/secondary/setup/rules/admission_age_limits', ['value' => ['6E' => ['max_age' => 13]], 'valid_from' => '2026-09-01', 'reason' => 'Jeu fictif'], self::ADMIN, self::TA, $s['school']);
        $old = self::pupil('Gaston', $s['sixth']);
        $old['identity']['birth_date'] = '2010-01-01';
        [$status, $problem] = $this->call('POST', '/secondary/enrolments', $old, self::ADMIN, self::TA, $s['school']);
        self::assertSame(422, $status);
        self::assertSame(EnrolmentService::AGE_ALERT, $problem['type'] ?? null);
        $this->grant(self::TA, 'scolarite', $s['school'], self::DESK);
        self::assertSame(403, $this->call('POST', '/secondary/enrolments', $old + ['age_decision_reason' => 'Dérogation'], 'scolarite', self::TA, $s['school'])[0], 'Décision réservée au chef.');
        $dossier = $this->ok('POST', '/secondary/enrolments', $old + ['age_decision_reason' => 'Dérogation motivée (jeu fictif)'], self::ADMIN, self::TA, $s['school']);
        self::assertTrue($dossier['records'][0]['age_alert']);
        self::assertSame(self::ADMIN, $dossier['records'][0]['decided_by']);
    }

    public function testExcluDefinitifEtReinscription(): void
    {
        $s = $this->school();
        $a = $this->ok('POST', '/secondary/enrolments', self::pupil('Hawa', $s['sixth']), self::ADMIN, self::TA, $s['school']);
        $b = $this->ok('POST', '/secondary/enrolments', self::pupil('Ibrahim', $s['sixth']), self::ADMIN, self::TA, $s['school']);
        $c = $this->ok('POST', '/secondary/enrolments', self::pupil('Jules', $s['sixth']), self::ADMIN, self::TA, $s['school']);
        $this->admitted = [$a['person_id'], $c['person_id']];
        $this->service(StudentRegistry::class)->markFinalExclusion(self::TA, $s['school'], $c['person_id'], '2027-05-02', 'CD-2027-01');

        $candidates = array_column($this->ok('GET', '/secondary/enrolment/reenrolments/candidates?from_academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $s['school']), 'admitted', 'person_id');
        self::assertTrue($candidates[$a['person_id']]);
        self::assertFalse($candidates[$b['person_id']]);
        $result = $this->ok('POST', '/secondary/enrolment/reenrolments', [
            'from_academic_year_id' => self::YEAR, 'class_id' => $s['fifth'], 'entry_date' => '2027-09-06',
            'students' => [['person_id' => $a['person_id']], ['person_id' => $b['person_id']], ['person_id' => $c['person_id']]],
        ], self::ADMIN, self::TA, $s['school']);
        self::assertSame([$a['person_id']], $result['enrolled']);
        $reasons = array_column($result['refused'], 'reason', 'person_id');
        self::assertStringContainsString('conseil', $reasons[$b['person_id']], 'Non admis : redoublement seulement.');
        self::assertStringContainsString('RG-INS-3', $reasons[$c['person_id']]);
        $result = $this->ok('POST', '/secondary/enrolment/reenrolments', [
            'from_academic_year_id' => self::YEAR, 'class_id' => $s['fifth'], 'entry_date' => '2027-09-06',
            'students' => [['person_id' => $c['person_id'], 'exclusion_decision_reason' => 'Réadmission décidée après entretien (fictif)']],
        ], self::ADMIN, self::TA, $s['school']);
        self::assertSame([$c['person_id']], $result['enrolled']);
        $row = $this->service(PdoStudentRegistry::class)->row(self::TA, $s['school'], $c['person_id']);
        self::assertSame(self::NEXT, $row['readmission_year_id'] ?? null);
    }

    public function testPreinscriptionTestDEntreeEtConversion(): void
    {
        $public = $this->school();
        $pre = $this->ok('POST', '/secondary/enrolment/preregistrations', ['last_name' => 'MBARGA', 'first_name' => 'Kevin', 'birth_date' => '2014-02-02', 'target_level_code' => '6E', 'channel' => 'desk', 'guardian_name' => 'Mme Mbarga', 'guardian_phone' => '+237600000002'], self::ADMIN, self::TA, $public['school']);
        self::assertSame(422, $this->call('PATCH', '/secondary/enrolment/preregistrations/' . $pre['id'], ['entry_test_score' => 12.5], self::ADMIN, self::TA, $public['school'])[0], 'Test d\'entrée : privé seulement.');
        self::assertSame(409, $this->call('POST', '/secondary/enrolment/preregistrations/' . $pre['id'] . '/convert', ['class_id' => $public['sixth'], 'entry_date' => '2026-09-07'], self::ADMIN, self::TA, $public['school'])[0]);
        $this->grant(self::TA, self::ADMIN, $public['school'], ['secondary.documents.issue']);
        $slip = $this->ok('POST', '/secondary/enrolment/preregistrations/' . $pre['id'] . '/slip', ['academic_year_label' => '2026-2027'], self::ADMIN, self::TA, $public['school']);
        self::assertNotSame('', $slip['document']['number'], 'DOC-01 émis.');
        self::assertNotNull($slip['preregistration']['person_id']);
        $this->ok('PATCH', '/secondary/enrolment/preregistrations/' . $pre['id'], ['status' => 'accepted'], self::ADMIN, self::TA, $public['school']);
        $dossier = $this->ok('POST', '/secondary/enrolment/preregistrations/' . $pre['id'] . '/convert', ['class_id' => $public['sixth'], 'entry_date' => '2026-09-07', 'guardians' => [['kind' => 'parent', 'identity' => ['last_name' => 'MBARGA', 'first_name' => 'Rose']]]], self::ADMIN, self::TA, $public['school']);
        self::assertSame('MBARGA', $dossier['last_name']);
        $list = $this->ok('GET', '/secondary/enrolment/preregistrations', null, self::ADMIN, self::TA, $public['school']);
        self::assertSame('enrolled', $list[0]['status']);

        $private = $this->school('college_prive', 'private_faith');
        $pre = $this->ok('POST', '/secondary/enrolment/preregistrations', ['last_name' => 'ABENA', 'first_name' => 'Luc', 'target_level_code' => '6E', 'channel' => 'online'], self::ADMIN, self::TA, $private['school']);
        $updated = $this->ok('PATCH', '/secondary/enrolment/preregistrations/' . $pre['id'], ['entry_test_score' => 12.5], self::ADMIN, self::TA, $private['school']);
        self::assertSame(12.5, $updated['entry_test_score']);
        self::assertSame(422, $this->call('PATCH', '/secondary/enrolment/preregistrations/' . $pre['id'], ['status' => 'refused'], self::ADMIN, self::TA, $private['school'])[0], 'Motif de refus obligatoire.');
    }

    public function testFichierDuPrimaireSigneEtIdempotent(): void
    {
        $_ENV['APP_KEY'] = 'cle-de-test-fictive';
        try {
            $s = $this->school();
            $json = json_encode(['format' => 'scholaris.public_primary.secondary_transfer.v1', 'issued_at' => '2026-07-01T10:00:00+01:00', 'pupil' => ['person_id' => 'p-1', 'last_name' => 'OWONA', 'first_name' => 'Marie', 'sex' => 'F', 'birth_date' => '2014-05-05'], 'exam' => ['code' => 'CEP', 'outcome' => 'passed'], 'annual_mentions' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signature = hash_hmac('sha256', $json, hash_hmac('sha256', 'scholaris.public_primary.secondary_transfer.v1', 'cle-de-test-fictive'));
            self::assertSame(422, $this->call('POST', '/secondary/enrolment/primary-files', ['dossier_json' => $json, 'signature' => str_repeat('0', 64), 'target_level_code' => '6E'], self::ADMIN, self::TA, $s['school'])[0], 'Signature invalide.');
            $first = $this->ok('POST', '/secondary/enrolment/primary-files', ['dossier_json' => $json, 'signature' => $signature, 'target_level_code' => '6E'], self::ADMIN, self::TA, $s['school']);
            self::assertFalse($first['duplicate']);
            self::assertSame('primary_file', $first['preregistration']['channel']);
            self::assertSame('female', $first['preregistration']['gender']);
            $again = $this->ok('POST', '/secondary/enrolment/primary-files', ['dossier_json' => $json, 'signature' => $signature, 'target_level_code' => '6E'], self::ADMIN, self::TA, $s['school']);
            self::assertTrue($again['duplicate']);
        } finally {
            unset($_ENV['APP_KEY']);
        }
    }

    public function testTransfertsSortantEtEntrant(): void
    {
        $s = $this->school();
        $dossier = $this->ok('POST', '/secondary/enrolments', self::pupil('Nadia', $s['sixth'], ['national_id' => 'CM2026-000777']), self::ADMIN, self::TA, $s['school']);
        $out = $this->ok('POST', '/secondary/enrolment/transfers/out', ['person_id' => $dossier['person_id'], 'other_school_label' => 'CES fictif de Garoua', 'transfer_date' => '2026-12-15', 'file_ref' => 'DOSSIER-01'], self::ADMIN, self::TA, $s['school']);
        self::assertSame('completed', $out['status']);
        self::assertNull($out['exeat_error'], (string) $out['exeat_error']);
        self::assertNotNull($out['exeat_document_id'], 'DOC-05 émis après la clôture de l\'inscription.');
        $after = $this->ok('GET', '/secondary/enrolment/students/' . $dossier['person_id'], null, self::ADMIN, self::TA, $s['school']);
        self::assertSame('transferred', $after['enrollments'][0]['status']);
        self::assertSame(404, $this->call('POST', '/secondary/enrolment/transfers/out', ['person_id' => $dossier['person_id'], 'other_school_label' => 'X', 'transfer_date' => '2026-12-16'], self::ADMIN, self::TA, $s['school'])[0]);

        $in = $this->ok('POST', '/secondary/enrolment/transfers/in', self::pupil('Olive', $s['sixth'], ['other_school_label' => 'Collège fictif de Maroua', 'transfer_date' => '2026-11-02', 'declared_national_id' => 'CM2026-000888']), self::ADMIN, self::TA, $s['school']);
        self::assertSame('in', $in['direction']);
        self::assertSame('recorded', $in['national_id_match']);
        self::assertCount(2, $this->ok('GET', '/secondary/enrolment/transfers', null, self::ADMIN, self::TA, $s['school']));
    }

    public function testImportCsvSimulationPuisApplication(): void
    {
        $s = $this->school();
        $template = $this->ok('GET', '/secondary/enrolment/imports/template', null, self::ADMIN, self::TA, $s['school']);
        self::assertContains('classe', $template['required']);
        $csv = "nom;prenoms;date_naissance;sexe;classe;matricule;nom_parent;telephone_parent\n"
            . "FOUDA;Pierre;12/03/2014;M;6e A;CM2026-001001;FOUDA;+237600000003\n"
            . "ESSOMBA;Rita;2014-04-04;F;6e A;;ESSOMBA;+237600000004\n"
            . "TABI;Zoe;2014-04-04;F;6e Z;;TABI;+237600000005\n";
        $body = ['format' => 'csv', 'content' => $csv, 'academic_year_id' => self::YEAR, 'entry_date' => '2026-09-07', 'dry_run' => true];
        $report = $this->ok('POST', '/secondary/enrolment/imports', $body, self::ADMIN, self::TA, $s['school']);
        self::assertSame(['valid' => 2, 'error' => 1, 'duplicate' => 0, 'total' => 3], $report['summary']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM se_enrolment_records')->fetchColumn(), 'Simulation : aucune écriture.');
        [$status] = $this->call('POST', '/secondary/enrolment/imports', ['dry_run' => false] + $body, self::ADMIN, self::TA, $s['school']);
        self::assertSame(422, $status, 'Fichier avec erreurs : rien n\'est appliqué.');
        $body['content'] = implode("\n", array_slice(explode("\n", $csv), 0, 3)) . "\n";
        $applied = $this->ok('POST', '/secondary/enrolment/imports', ['dry_run' => false] + $body, self::ADMIN, self::TA, $s['school']);
        self::assertSame(2, $applied['summary']['enrolled']);
        $again = $this->ok('POST', '/secondary/enrolment/imports', $body, self::ADMIN, self::TA, $s['school']);
        self::assertSame(1, $again['summary']['duplicate'], 'Même matricule déjà inscrit cette année : ignoré.');
    }

    public function testDroitsEtIsolation(): void
    {
        $s = $this->school();
        $dossier = $this->ok('POST', '/secondary/enrolments', self::pupil('Paula', $s['sixth']), self::ADMIN, self::TA, $s['school']);
        $this->access->grant('lecteur', $s['school']);
        self::assertSame(403, $this->call('GET', '/secondary/enrolment/students', null, 'lecteur', self::TA, $s['school'])[0]);
        $other = $this->factory->opened(self::TB, 'ces', null, 'e-ces-b');
        $this->grant(self::TB, 'admin-b', $other, self::DESK);
        self::assertSame(404, $this->call('GET', '/secondary/enrolment/students/' . $dossier['person_id'], null, 'admin-b', self::TB, $other)[0]);
        self::assertSame(404, $this->call('PUT', '/secondary/students/' . $dossier['person_id'] . '/national-id', ['national_id' => 'CM2026-009999'], 'admin-b', self::TB, $other)[0]);
        self::assertSame([], $this->ok('GET', '/secondary/enrolment/students', null, 'admin-b', self::TB, $other));
    }
}
