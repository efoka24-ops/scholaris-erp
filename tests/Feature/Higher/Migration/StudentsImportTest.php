<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Migration;

use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Tests\Support\Higher\Migration\MigrationTestCase;

/**
 * Reprise des étudiants en cours : simulation sans écriture, rapport ligne par ligne, application transactionnelle, idempotence,
 * annulation, périmètre, isolation entre clients et volume. Étudiants fictifs.
 */
final class StudentsImportTest extends MigrationTestCase
{
    protected function setUp(): void
    {
        $this->bootMigration();
    }

    public function testLaSimulationNEcritRienEtRapporteLigneParLigne(): void
    {
        $csv = $this->csv(
            ['matricule', 'nom', 'prenom', 'date_naissance', 'sexe', 'telephone', 'email', 'filiere', 'niveau', 'groupe'],
            [
                ['26EC-CF-0001', 'Fictif', 'Awa', '2005-03-14', 'F', '+237600000001', '', 'CF', 'L1', 'G1'],
                ['26EC-CF-0002', 'Fictif', 'Issa', '14/03/2004', 'M', '', '', 'CF', 'L9', 'G1'],
                ['26EC-CF-0001', 'Fictif', 'Awa', '2005-03-14', 'F', '+237600000001', '', 'CF', 'L1', 'G1'],
                ['', 'Mineur', 'Ali', '2015-01-01', 'M', '', 'ali@example.org', 'CF', 'L1', ''],
                ['26EC-CF-0005', 'Fictif', 'Eva', '2005-03-14', 'F', '+237600000005', 'pas-un-email', 'XX', 'L1', ''],
            ],
        );
        $batch = $this->simulate('students', $csv);

        self::assertSame('simulated', $batch['status']);
        self::assertSame(['total' => 5, 'ok' => 1, 'warning' => 0, 'duplicate' => 0, 'error' => 4, 'applied' => 0], $batch['counts']);
        self::assertFalse($batch['applicable']);
        $byLine = [];
        foreach ($batch['lines'] as $line) {
            $byLine[$line['line']] = $line;
        }
        self::assertStringContainsString('L9', implode(' ', $byLine[3]['errors']));
        self::assertStringContainsString('doublon dans le fichier', implode(' ', $byLine[4]['errors']));
        self::assertStringContainsString('mineur', implode(' ', $byLine[5]['errors']));
        self::assertStringContainsString('e-mail', implode(' ', $byLine[6]['errors']));
        self::assertSame(0, $this->rows('hi_enrolments'), 'La simulation n\'écrit aucune inscription.');
        self::assertSame(0, $this->rows('hi_students'));
        self::assertSame([], $this->migrationFakes->learners, 'Aucune fiche personne créée.');
        self::assertSame(1, $this->rows('hi_import_batches'));
    }

    public function testApplicationRefuseeTantQuIlResteUneErreurOuSansDoubleAuthentification(): void
    {
        $bad = $this->simulate('students', $this->studentsCsv(1) . "26EC-CF-0099;Fictif;Zoe;2005-03-14;;F;;;ZZ;L1;;;;;\n");
        [$status, $problem] = $this->call('POST', '/higher/migration/batches/' . $bad['id'] . '/apply', [], self::IMPORTER);
        self::assertSame(409, $status);
        self::assertStringContainsString('1 erreur', (string) $problem['detail']);

        $good = $this->simulate('students', $this->studentsCsv(2));
        self::assertTrue($good['applicable']);
        [$status, $problem] = $this->call('POST', '/higher/migration/batches/' . $good['id'] . '/apply', [], self::IMPORTER_NO_2FA);
        self::assertSame(403, $status);
        self::assertStringContainsString('second-factor-required', (string) $problem['type']);
        self::assertSame(0, $this->rows('hi_enrolments'));
    }

    public function testApplicationCreeFichesEtInscriptionsPuisEstIdempotente(): void
    {
        $csv = $this->studentsCsv(3);
        $batch = $this->simulate('students', $csv);
        self::assertSame(3, $batch['counts']['ok']);

        $applied = $this->apply($batch['id']);
        self::assertSame(['applied', 3], [$applied['status'], $applied['counts']['applied']]);
        self::assertCount(3, $this->migrationFakes->learners);
        self::assertSame(3, $this->rows('hi_enrolments'));
        $student = $this->service(StudentDirectory::class)->byMatricule(self::T, self::E, '26EC-CF-0002');
        self::assertNotNull($student, 'Le matricule d\'origine est conservé.');
        $enrolment = $this->service(StudentDirectory::class)->enrolmentsOf(self::T, self::E, $student->personId)[0];
        self::assertSame(['enrolled', self::YEAR, $this->org['l1'], $this->org['g1'], $this->curriculum['id']], [$enrolment->status, $enrolment->academicYearId, $enrolment->levelId, $enrolment->groupId, $enrolment->curriculumId]);

        $again = $this->simulate('students', $csv);
        self::assertSame(['ok' => 0, 'duplicate' => 3, 'error' => 0], ['ok' => $again['counts']['ok'], 'duplicate' => $again['counts']['duplicate'], 'error' => $again['counts']['error']]);
        $second = $this->apply($again['id']);
        self::assertSame(0, $second['counts']['applied']);
        self::assertCount(3, $this->migrationFakes->learners, 'Aucune fiche en double.');
        self::assertSame(3, $this->rows('hi_enrolments'));

        $actions = array_column($this->audit->entries, 'action');
        self::assertContains('higher.migration.simulated', $actions);
        self::assertContains('higher.migration.applied', $actions);
        self::assertContains('higher.enrolment.imported', $actions);
    }

    public function testFiliereSansAutorisationEstRefuseeALaSimulation(): void
    {
        $department = (string) $this->ok('POST', '/higher/departments', ['school_id' => $this->org['school'], 'code' => 'DGL', 'name' => 'Gestion'])['id'];
        $program = (string) $this->ok('POST', '/higher/programs', [
            'department_id' => $department, 'code' => 'GL', 'name' => 'Gestion logistique', 'regime' => 'lmd', 'teaching_language' => 'fr', 'study_mode' => 'day', 'duration_years' => 3,
        ])['id'];
        $this->ok('POST', '/higher/levels', ['program_id' => $program, 'ordinal' => 1, 'code' => 'L1', 'name' => 'Licence 1']);
        $csv = $this->csv(['nom', 'prenom', 'date_naissance', 'filiere', 'niveau'], [['Fictif', 'Noa', '2005-03-14', 'GL', 'L1']]);
        $batch = $this->simulate('students', $csv);

        self::assertSame(1, $batch['counts']['error']);
        self::assertStringContainsString('Inscription impossible', implode(' ', $batch['lines'][0]['errors']));
    }

    public function testAnnulationDuLotTantQuAucuneDonneeNeDepend(): void
    {
        $batch = $this->simulate('students', $this->studentsCsv(2));
        $this->apply($batch['id']);
        self::assertSame(2, $this->rows('hi_enrolments'));

        [$status] = $this->call('POST', '/higher/migration/batches/' . $batch['id'] . '/revert', ['reason' => 'x'], self::IMPORTER);
        self::assertSame(422, $status, 'Motif obligatoire.');
        $reverted = $this->ok('POST', '/higher/migration/batches/' . $batch['id'] . '/revert', ['reason' => 'Mauvais fichier source.'], self::IMPORTER);
        self::assertSame('reverted', $reverted['status']);
        self::assertSame(0, $this->rows('hi_enrolments'));
        self::assertSame(0, $this->rows('hi_students'));
        self::assertContains('higher.migration.reverted', array_column($this->audit->entries, 'action'));

        $redo = $this->simulate('students', $this->studentsCsv(2));
        self::assertSame(2, $redo['counts']['ok'], 'Après annulation, les clés de reprise sont libres.');
    }

    public function testAnnulationRefuseeQuandUnLotDependantExiste(): void
    {
        $students = $this->simulate('students', $this->studentsCsv(1));
        $this->apply($students['id']);
        $balances = $this->simulate('balances', $this->csv(
            ['matricule', 'filiere', 'niveau', 'solde_xaf', 'tranche1_date', 'tranche1_xaf', 'tranche2_date', 'tranche2_xaf'],
            [['26EC-CF-0001', 'CF', 'L1', '240000', '2026-11-15', '120000', '2027-01-15', '120000']],
        ));
        $this->apply($balances['id']);

        [$status, $problem] = $this->call('POST', '/higher/migration/batches/' . $students['id'] . '/revert', ['reason' => 'Annulation demandée.'], self::IMPORTER);
        self::assertSame(409, $status);
        self::assertStringContainsString('dépendent', (string) $problem['detail']);
        self::assertSame(1, $this->rows('hi_enrolments'));
    }

    public function testPermissionPerimetreEtIsolationEntreClients(): void
    {
        [$status] = $this->call('POST', '/higher/migration/batches', ['kind' => 'students', 'academic_year_id' => self::YEAR, 'content' => $this->studentsCsv(1)], self::OUTSIDER);
        self::assertSame(403, $status);

        $batch = $this->simulate('students', $this->studentsCsv(1));
        [$status] = $this->call('GET', '/higher/migration/batches/' . $batch['id'], null, self::IMPORTER_B, self::TB, self::EB);
        self::assertSame(404, $status, 'Un autre client ne voit pas le lot.');
        [$status] = $this->call('POST', '/higher/migration/batches/' . $batch['id'] . '/apply', [], self::IMPORTER_B, self::TB, self::EB);
        self::assertSame(404, $status);
        $listB = $this->ok('GET', '/higher/migration/batches', null, self::IMPORTER_B, self::TB, self::EB);
        self::assertSame([], $listB);

        [$status, $problem] = $this->call('POST', '/higher/migration/batches', ['kind' => 'students', 'academic_year_id' => self::YEAR, 'content' => $this->studentsCsv(1), 'dry_run' => false], self::IMPORTER);
        self::assertSame(422, $status);
        self::assertStringContainsString('simulation', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
    }

    public function testFichierIllisibleOuColonnesAbsentes(): void
    {
        [$status, $problem] = $this->call('POST', '/higher/migration/batches', ['kind' => 'students', 'academic_year_id' => self::YEAR, 'content' => "nom;prenom\nA;B\n"], self::IMPORTER);
        self::assertSame(422, $status);
        self::assertStringContainsString('Colonne(s) obligatoire(s)', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
        [$status] = $this->call('POST', '/higher/migration/batches', ['kind' => 'students', 'academic_year_id' => self::YEAR, 'content_base64' => base64_encode("nom;prenom;date_naissance;filiere;niveau\n\xE9;B;2000-01-01;CF;L1\n")], self::IMPORTER);
        self::assertSame(422, $status, 'Encodage non UTF-8 refusé.');
    }

    public function testModelesTelechargeables(): void
    {
        foreach (['students', 'credits', 'balances', 'staff', 'marks'] as $kind) {
            $response = $this->api('GET', '/higher/migration/templates/' . $kind, self::IMPORTER, self::T, self::E)[0];
            self::assertSame(200, $response->getStatusCode(), $kind);
            self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString(';', (string) $response->getBody());
        }
        self::assertSame(404, $this->api('GET', '/higher/migration/templates/inconnu', self::IMPORTER, self::T, self::E)[0]->getStatusCode());
    }

    public function testDeuxMilleLignesEnUnTempsRaisonnable(): void
    {
        $csv = $this->studentsCsv(2000);
        $started = microtime(true);
        $batch = $this->simulate('students', $csv);
        $simulated = microtime(true) - $started;

        self::assertSame(['total' => 2000, 'ok' => 2000, 'error' => 0], ['total' => $batch['counts']['total'], 'ok' => $batch['counts']['ok'], 'error' => $batch['counts']['error']]);
        self::assertLessThan(60.0, $simulated, 'Simulation de 2 000 lignes.');
        self::assertSame(0, $this->rows('hi_enrolments'));

        $started = microtime(true);
        $applied = $this->apply($batch['id']);
        self::assertSame(2000, $applied['counts']['applied']);
        self::assertLessThan(180.0, microtime(true) - $started, 'Application de 2 000 lignes.');
        self::assertSame(2000, $this->rows('hi_enrolments'));
    }
}
