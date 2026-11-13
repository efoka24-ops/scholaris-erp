<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Migration;

use Tests\Support\Higher\Migration\MigrationTestCase;

/**
 * Reprise des UE capitalisées et dettes, des soldes d'ouverture, du personnel et des notes de l'année en cours : simulation, application,
 * idempotence, annulation et règles propres à chaque jeu. Personnes fictives.
 */
final class OtherDatasetsImportTest extends MigrationTestCase
{
    protected function setUp(): void
    {
        $this->bootMigration();
        $this->apply($this->simulate('students', $this->studentsCsv(2))['id']);
    }

    public function testUeCapitaliseesEtDettesAppliquesPuisAnnules(): void
    {
        $csv = $this->csv(
            ['matricule', 'filiere', 'niveau', 'annee', 'ue_code', 'ue_intitule', 'credits', 'moyenne', 'statut'],
            [
                ['26EC-CF-0001', 'CF', 'L1', '2024-2025', 'UE101', 'Comptabilité générale', '6', '12,50', 'capitalisee'],
                ['26EC-CF-0001', 'CF', 'L1', '2024-2025', 'UE102', 'Mathématiques financières', '6', '', 'dette'],
                ['26EC-CF-0001', 'CF', 'L1', self::YEAR, 'UE103', '', '6', '', 'capitalisee'],
                ['26EC-CF-0009', 'CF', 'L1', '2024-2025', 'UE104', '', '6', '', 'dette'],
            ],
        );
        $batch = $this->simulate('credits', $csv);
        self::assertSame(2, $batch['counts']['error'], 'Année en cours et matricule inconnu refusés.');

        $good = $this->simulate('credits', $this->csv(
            ['matricule', 'filiere', 'niveau', 'annee', 'ue_code', 'ue_intitule', 'credits', 'moyenne', 'statut'],
            [
                ['26EC-CF-0001', 'CF', 'L1', '2024-2025', 'UE101', 'Comptabilité générale', '6', '12,50', 'capitalisee'],
                ['26EC-CF-0001', 'CF', 'L1', '2024-2025', 'UE102', 'Mathématiques financières', '6', '', 'dette'],
            ],
        ));
        $applied = $this->apply($good['id']);
        self::assertSame(2, $applied['counts']['applied']);
        self::assertSame(1, $this->rows('hi_unit_credits'));
        self::assertSame(1, $this->rows("hi_learning_contract_items WHERE item_origin = 'debt'"));

        $replay = $this->simulate('credits', $this->csv(
            ['matricule', 'filiere', 'niveau', 'annee', 'ue_code', 'credits', 'statut'],
            [['26EC-CF-0001', 'CF', 'L1', '2024-2025', 'UE101', '6', 'capitalisee']],
        ));
        self::assertSame(1, $replay['counts']['duplicate']);

        $this->ok('POST', '/higher/migration/batches/' . $good['id'] . '/revert', ['reason' => 'Fichier erroné.'], self::IMPORTER);
        self::assertSame(0, $this->rows('hi_unit_credits'));
        self::assertSame(0, $this->rows("hi_learning_contract_items WHERE item_origin = 'debt'"));
    }

    public function testSoldesDOuvertureEchelonnesSansAnnulationPossible(): void
    {
        $header = ['matricule', 'filiere', 'niveau', 'solde_xaf', 'tranche1_date', 'tranche1_xaf', 'tranche2_date', 'tranche2_xaf'];
        $bad = $this->simulate('balances', $this->csv($header, [['26EC-CF-0001', 'CF', 'L1', '240000', '2026-11-15', '100000', '2027-01-15', '100000']]));
        self::assertStringContainsString('ne totalisent pas', implode(' ', $bad['lines'][0]['errors']));

        $batch = $this->simulate('balances', $this->csv($header, [
            ['26EC-CF-0001', 'CF', 'L1', '240000', '2026-11-15', '120000', '2027-01-15', '120000'],
            ['26EC-CF-0002', 'CF', 'L1', '50000', '2026-11-30', '', '', ''],
        ]));
        self::assertSame(2, $batch['counts']['ok']);
        self::assertSame(0, $this->rows('hi_student_accounts'));
        $this->apply($batch['id']);
        self::assertSame(2, $this->rows('hi_student_accounts'));

        $again = $this->simulate('balances', $this->csv($header, [['26EC-CF-0001', 'CF', 'L1', '240000', '2026-11-15', '120000', '2027-01-15', '120000']]));
        self::assertSame(1, $again['counts']['duplicate']);

        [$status, $problem] = $this->call('POST', '/higher/migration/batches/' . $batch['id'] . '/revert', ['reason' => 'Annulation demandée.'], self::IMPORTER);
        self::assertSame(409, $status);
        self::assertStringContainsString('ne s\'annulent pas', (string) $problem['detail']);
    }

    public function testPersonnelEtAffectations(): void
    {
        $this->migrationFakes->addStaff(self::T, 'staff-p1');
        $header = ['personne_id', 'departement', 'statut', 'grade', 'fonctionnaire', 'filiere', 'niveau', 'groupe', 'ec_code', 'nature', 'heures'];
        $bad = $this->simulate('staff', $this->csv($header, [
            ['staff-inconnu', 'DCF', 'vacataire', '', 'non', '', '', '', '', '', ''],
            ['staff-p1', 'DCF', 'vacataire', '', 'non', 'CF', 'L1', 'G1', 'XYZ', 'cm', '18'],
        ]));
        self::assertSame(2, $bad['counts']['error']);

        $batch = $this->simulate('staff', $this->csv($header, [
            ['staff-p1', 'DCF', 'vacataire', 'Assistant', 'non', 'CF', 'L1', 'G1', 'CG', 'cm', '18'],
            ['staff-p1', 'DCF', 'vacataire', 'Assistant', 'non', 'CF', 'L1', 'G1', 'CG', 'td', '12'],
        ]));
        self::assertSame(2, $batch['counts']['ok'], json_encode($batch['lines'], JSON_UNESCAPED_UNICODE));
        $this->apply($batch['id']);
        self::assertSame(1, $this->rows('hi_teacher_profiles'));
        self::assertSame(2, $this->rows('hi_assignments'));
        self::assertCount(2, $this->migrationFakes->assignments);

        $this->ok('POST', '/higher/migration/batches/' . $batch['id'] . '/revert', ['reason' => 'Mauvaises affectations.'], self::IMPORTER);
        self::assertSame(0, $this->rows('hi_assignments'));
        self::assertSame(0, $this->rows('hi_teacher_profiles'));
        self::assertCount(0, $this->migrationFakes->assignments);
    }

    public function testNotesDeLAnneeEnCoursVerrouilleesAvecMotifReprise(): void
    {
        $header = ['matricule', 'filiere', 'niveau', 'groupe', 'ec_code', 'evaluation', 'note'];
        $bad = $this->simulate('marks', $this->csv($header, [
            ['26EC-CF-0001', 'CF', 'L1', 'G1', 'CG', 'cc', '13,6'],
            ['26EC-CF-0002', 'CF', 'L1', 'G1', 'CG', 'cc', '25'],
            ['26EC-CF-0002', 'CF', 'L1', 'G1', 'ZZZ', 'cc', '12'],
        ]));
        self::assertSame(3, $bad['counts']['error']);

        $batch = $this->simulate('marks', $this->csv($header, [
            ['26EC-CF-0001', 'CF', 'L1', 'G1', 'CG', 'cc', '13,5'],
            ['26EC-CF-0002', 'CF', 'L1', 'G1', 'CG', 'cc', 'ABJ'],
        ]));
        self::assertSame(2, $batch['counts']['ok']);
        self::assertSame(0, $this->rows('hi_grade_assessments'));
        $this->apply($batch['id']);
        self::assertSame(1, $this->rows("hi_grade_assessments WHERE status = 'locked'"));
        self::assertSame(2, $this->rows('hi_grade_marks'));
        self::assertSame(2, $this->rows("hi_grade_mark_events WHERE event_kind = 'reprise' AND reason = 'Reprise de données'"));

        $again = $this->simulate('marks', $this->csv($header, [['26EC-CF-0001', 'CF', 'L1', 'G1', 'CG', 'cc', '13,5']]));
        self::assertSame(1, $again['counts']['duplicate']);

        $this->ok('POST', '/higher/migration/batches/' . $batch['id'] . '/revert', ['reason' => 'Notes mal saisies.'], self::IMPORTER);
        self::assertSame(0, $this->rows('hi_grade_assessments'));
        self::assertSame(0, $this->rows('hi_grade_marks'));
    }

    public function testFichierXlsxLuCommeLeCsv(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('Extension zip absente.');
        }
        $header = ['matricule', 'nom', 'prenom', 'date_naissance', 'filiere', 'niveau', 'groupe'];
        $xlsx = (new \Scholaris\Core\Http\XlsxWriter())->build($header, [['26EC-CF-0077', 'Fictif', 'Xlsx', '2005-03-14', 'CF', 'L1', 'G1']], 'Etudiants');
        $batch = $this->ok('POST', '/higher/migration/batches', ['kind' => 'students', 'academic_year_id' => self::YEAR, 'format' => 'xlsx', 'content_base64' => base64_encode($xlsx), 'dry_run' => true], self::IMPORTER);

        self::assertSame(['total' => 1, 'error' => 0, 'applicable' => true], ['total' => $batch['counts']['total'], 'error' => $batch['counts']['error'], 'applicable' => $batch['applicable']]);
    }
}
