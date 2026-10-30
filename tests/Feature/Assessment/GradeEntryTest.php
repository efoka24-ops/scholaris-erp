<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;

/** Grille de saisie, saisie en lot, hors ligne (client_uuid, version), import CSV, périmètre enseignant (T118). */
final class GradeEntryTest extends AssessmentTestCase
{
    private string $math;
    private string $interro;
    private string $devoir;

    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->seedClass();
        $this->math = $this->subject();
        $this->interro = $this->assessment($this->math, 'Interrogation écrite', 1.0);
        $this->devoir = $this->assessment($this->math, 'Devoir', 2.0);
    }

    public function testLaGrilleAfficheColonnesPoidsMoyenneAppreciationEtCompteurs(): void
    {
        $this->grade($this->interro, 'p1', ['value' => 14]);
        $this->grade($this->devoir, 'p1', ['value' => 17]);
        $this->grade($this->interro, 'p2', ['absence' => 'justified']);
        $this->grade($this->devoir, 'p2', ['value' => 9]);
        $this->grade($this->interro, 'p3', ['value' => 20]);

        [$response, $json] = $this->api('GET', '/grade-sheets?structure_id=c1&period_id=seq1', 'reader');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $grid = $json['data'];
        self::assertSame(['Interrogation écrite', 'Devoir'], array_column($grid['columns'], 'title'));
        self::assertEquals([1.0, 2.0], array_column($grid['columns'], 'weight'));
        self::assertEquals([20.0, 20.0], array_column($grid['columns'], 'max_score'));

        $rows = array_column($grid['rows'], null, 'person_id');
        self::assertEquals(16.0, $rows['p1']['average'], '(14 + 17 x 2) / 3 = 16');
        self::assertSame('Très bien', $rows['p1']['appraisal']);
        self::assertEquals(9.0, $rows['p2']['average'], 'Absent justifié exclu du calcul : seule la note du devoir compte.');
        self::assertSame('absent_justified', $rows['p2']['cells'][$this->interro]['state']);
        self::assertSame('Doit fournir plus d\'efforts', $rows['p2']['appraisal']);
        self::assertSame('missing', $rows['p3']['cells'][$this->devoir]['state']);
        self::assertSame(['MAT-P1', 'MAT-P2'], [$rows['p1']['matricule'], $rows['p2']['matricule']]);
        self::assertSame(['errors' => 0, 'missing' => 3, 'absent_justified' => 1], $grid['counters']);
    }

    public function testSaisieEnLotAtomiqueAvecMessageParLigne(): void
    {
        [$response, $json] = $this->api('POST', '/grades/batch', 'censor', ['assessment_id' => $this->devoir, 'entries' => [
            ['person_id' => 'p1', 'value' => 12],
            ['person_id' => 'p2', 'value' => 14],
            ['person_id' => 'p3', 'value' => 11],
            ['person_id' => 'p4', 'value' => 22],
        ]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['Ligne 4 · Devoir : 22 dépasse le maximum de 20. Corrigez avant de valider.'], $json['errors']['entries']);
        self::assertSame([], $this->rows('SELECT id FROM grades'), 'Une erreur et rien n\'est enregistré.');

        [$ok, $result] = $this->api('POST', '/grades/batch', 'censor', ['assessment_id' => $this->devoir, 'entries' => [
            ['person_id' => 'p1', 'value' => 12], ['person_id' => 'p2', 'absence' => 'unjustified'],
        ]]);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0], $result['data']);
    }

    public function testUnEnseignantNeSaisitQueSesEleves(): void
    {
        $this->teaching->classes['t1'] = ['p1', 'p2'];
        $own = $this->assessment($this->math, 'Interro enseignant', 1.0, 20.0, [], 'teacher');
        self::assertSame('t1', $this->rows('SELECT teacher_person_id FROM assessments WHERE id = ' . $this->connections()->pdo()->quote($own))[0]['teacher_person_id'] ?? null);

        [$mine] = $this->api('POST', '/grades', 'teacher', ['assessment_id' => $own, 'person_id' => 'p1', 'value' => 15]);
        self::assertSame(201, $mine->getStatusCode(), (string) $mine->getBody());

        [$foreign] = $this->api('POST', '/grades', 'teacher', ['assessment_id' => $own, 'person_id' => 'p3', 'value' => 15]);
        self::assertSame(403, $foreign->getStatusCode(), 'p3 n\'est pas dans les classes de l\'enseignant.');

        [$batch] = $this->api('POST', '/grades/batch', 'teacher', ['assessment_id' => $own, 'entries' => [['person_id' => 'p1', 'value' => 10], ['person_id' => 'p4', 'value' => 10]]]);
        self::assertSame(403, $batch->getStatusCode());

        $this->actors->persons['teacher2'] = 't2';
        $this->teaching->classes['t2'] = ['p1', 'p2', 'p3', 'p4'];
        [$other] = $this->api('POST', '/grades', 'teacher2', ['assessment_id' => $own, 'person_id' => 'p1', 'value' => 10]);
        self::assertSame(403, $other->getStatusCode(), 'L\'épreuve est confiée à un autre enseignant.');

        [$censor] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $own, 'person_id' => 'p3', 'value' => 10]);
        self::assertSame(201, $censor->getStatusCode(), 'Celui qui valide saisit pour toute la classe.');
    }

    public function testSansAucunDroitDuModuleLaSaisieEtLaLectureSontRefusees(): void
    {
        [$write] = $this->api('POST', '/grades', 'parent', ['assessment_id' => $this->devoir, 'person_id' => 'p1', 'value' => 10]);
        [$read] = $this->api('GET', '/grade-sheets?structure_id=c1&period_id=seq1', 'parent');
        [$readOnly] = $this->api('POST', '/grades', 'reader', ['assessment_id' => $this->devoir, 'person_id' => 'p1', 'value' => 10]);

        self::assertSame([403, 403, 403], [$write->getStatusCode(), $read->getStatusCode(), $readOnly->getStatusCode()]);
    }

    public function testHorsLigneUnRejeuNeCreeJamaisDeDoublon(): void
    {
        $uuid = '01J9T0000000000000000AAAA1';
        $entry = ['client_uuid' => $uuid, 'assessment_id' => $this->devoir, 'person_id' => 'p1', 'base_version' => 0, 'value' => 13];

        [$first, $one] = $this->api('POST', '/grades/sync', 'teacher', ['entries' => [$entry]]);
        [$replay, $two] = $this->api('POST', '/grades/sync', 'teacher', ['entries' => [$entry]]);

        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame('created', $one['data']['results'][0]['status']);
        self::assertSame(200, $replay->getStatusCode());
        self::assertSame('duplicate', $two['data']['results'][0]['status']);
        self::assertCount(1, $this->rows('SELECT id FROM grades'));
        self::assertCount(1, $this->rows('SELECT id FROM grade_events'));
    }

    public function testHorsLigneUneVersionPerimeeDonne409AvecLesDeuxVersions(): void
    {
        $server = $this->grade($this->devoir, 'p1', ['value' => 10]);
        $this->grade($this->devoir, 'p1', ['value' => 11]);

        [$response, $json] = $this->api('POST', '/grades/sync', 'censor', ['entries' => [
            ['client_uuid' => '01J9T0000000000000000AAAA2', 'assessment_id' => $this->devoir, 'person_id' => 'p1', 'base_version' => $server['version'], 'value' => 15],
            ['client_uuid' => '01J9T0000000000000000AAAA3', 'assessment_id' => $this->devoir, 'person_id' => 'p2', 'base_version' => 0, 'value' => 9],
        ]]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['conflict', 'created'], array_column($json['results'], 'status'));
        $conflict = $json['results'][0];
        self::assertEquals(11.0, $conflict['server']['score']);
        self::assertSame(2, $conflict['server']['version']);
        self::assertEquals(15.0, $conflict['client']['score']);
        self::assertSame(1, $conflict['client']['base_version']);
        self::assertEquals(11.0, (float) $this->rows('SELECT score FROM grades WHERE person_id = \'p1\'')[0]['score'], 'Jamais écrasée en silence.');
    }

    public function testSaisieEnLigneAvecVersionAttendueEnConflit(): void
    {
        $this->grade($this->devoir, 'p1', ['value' => 10]);
        $this->grade($this->devoir, 'p1', ['value' => 11]);

        [$response, $json] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $this->devoir, 'person_id' => 'p1', 'value' => 14, 'expected_version' => 1]);

        self::assertSame(409, $response->getStatusCode());
        self::assertEquals(11.0, $json['conflicts'][0]['server']['score']);
        self::assertEquals(14.0, $json['conflicts'][0]['client']['score']);
    }

    public function testHorsLigneUneNoteInvalideOuVerrouilleeEstRejeteeSansBloquerLesAutres(): void
    {
        [$response, $json] = $this->api('POST', '/grades/sync', 'censor', ['entries' => [
            ['client_uuid' => '01J9T0000000000000000BBBB1', 'assessment_id' => $this->devoir, 'person_id' => 'p1', 'base_version' => 0, 'value' => 25],
            ['client_uuid' => '01J9T0000000000000000BBBB2', 'assessment_id' => $this->devoir, 'person_id' => 'p2', 'base_version' => 0, 'value' => 12],
        ]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['rejected', 'created'], array_column($json['data']['results'], 'status'));
        self::assertStringContainsString('dépasse le maximum de 20', $json['data']['results'][0]['message']);
    }

    public function testImportCsvUtf8PointVirguleAvecAbsentEtVirguleDecimale(): void
    {
        $csv = "\xEF\xBB\xBFmatricule;nom;note\r\nMAT-P1;Abega Ada;14,5\r\nMAT-P2;Bello Boris;ABS\r\nMAT-P3;Chi Chloé;ABI\r\nMAT-P4;Dikongue Dani;\r\n";
        $response = $this->upload('/grades/import?assessment_id=' . $this->devoir, 'censor', $csv);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['created' => 3, 'updated' => 0, 'unchanged' => 0], $this->decode($response)['data']);
        $rows = array_column($this->rows('SELECT person_id, score, absence FROM grades'), null, 'person_id');
        self::assertEquals(14.5, (float) $rows['p1']['score']);
        self::assertSame('justified', $rows['p2']['absence']);
        self::assertSame('unjustified', $rows['p3']['absence']);
        self::assertArrayNotHasKey('p4', $rows);
        self::assertSame('import', $this->rows('SELECT event_kind FROM grade_events LIMIT 1')[0]['event_kind']);
    }

    public function testImportCsvWindows1252VirguleEtIdentifiantPersonne(): void
    {
        $csv = mb_convert_encoding("person_id,nom,note\r\np1,Abega Éloïse,12.25\r\np2,Bello Zoé,7\r\n", 'Windows-1252', 'UTF-8');
        self::assertFalse(mb_check_encoding($csv, 'UTF-8'));

        $response = $this->upload('/grades/import?assessment_id=' . $this->devoir, 'censor', $csv);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertEquals([12.25, 7.0], array_map(static fn (array $r): float => (float) $r['score'], $this->rows('SELECT score FROM grades ORDER BY person_id')));
    }

    public function testImportCsvAvecErreursNEnregistreRienEtRapporteChaqueLigne(): void
    {
        $csv = "matricule;nom;note\nMAT-P1;A;14\nMAT-ZZ;Inconnu;12\nMAT-P3;C;22\nMAT-P4;D;beaucoup\n";
        $response = $this->upload('/grades/import?assessment_id=' . $this->devoir, 'censor', $csv);
        $json = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(3, $json['errors']['file']);
        self::assertStringContainsString('Ligne 3 · élève inconnu « MAT-ZZ »', $json['errors']['file'][0]);
        self::assertStringContainsString('Ligne 4 · Devoir : 22 dépasse le maximum de 20', $json['errors']['file'][1]);
        self::assertStringContainsString('Ligne 5', $json['errors']['file'][2]);
        self::assertSame([], $this->rows('SELECT id FROM grades'));
    }

    public function testImportAvecEnTeteInconnuOuFichierVide(): void
    {
        $bad = $this->upload('/grades/import?assessment_id=' . $this->devoir, 'censor', "colonne1;colonne2\na;b\n");
        $empty = $this->upload('/grades/import?assessment_id=' . $this->devoir, 'censor', '');

        self::assertSame(422, $bad->getStatusCode());
        self::assertSame(400, $empty->getStatusCode());
    }

    public function testModeleCsvATelecharger(): void
    {
        $response = $this->call('GET', '/api/v1/grades/import-template?assessment_id=' . $this->devoir, ['Authorization' => 'Bearer censor:t1', 'X-Establishment-Id' => 'e1']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString("matricule;nom;note\r\nMAT-P1;Ada Abega;\r\n", $body);
    }

    public function testStatistiquesDeSequence(): void
    {
        foreach (['p1' => 14, 'p2' => 8, 'p3' => 10, 'p4' => 18] as $person => $value) {
            $this->grade($this->devoir, $person, ['value' => $value]);
        }

        [$response, $json] = $this->api('GET', '/sequence-statistics?structure_id=c1&period_id=seq1&subject_id=' . $this->math, 'reader');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertEquals(['count' => 4, 'average' => 12.5, 'pass_rate' => 75.0, 'min' => 8.0, 'max' => 18.0, 'students' => 4], $json['data']['sequence']);
        self::assertSame(4, $json['data']['assessments'][1]['count']);
        self::assertSame(0, $json['data']['assessments'][0]['count']);

        [$missing] = $this->api('GET', '/sequence-statistics?period_id=seq1', 'reader');
        self::assertSame(422, $missing->getStatusCode());
    }

    public function testEpreuveAnonymeMasqueLIdentiteJusquALaLeveeDeLAnonymat(): void
    {
        $anonymous = $this->assessment($this->math, 'Composition anonyme', 3.0, 20.0, ['anonymous' => true]);
        $this->grade($anonymous, 'p1', ['value' => 15]);

        [, $sheet] = $this->api('GET', '/grade-sheets/' . $anonymous, 'reader');
        foreach ($sheet['data']['rows'] as $row) {
            self::assertNull($row['name']);
            self::assertNull($row['person_id']);
            self::assertMatchesRegularExpression('/^[A-F0-9]{6}$/', $row['anonymous_code']);
        }
        [, $grid] = $this->api('GET', '/grade-sheets?structure_id=c1&period_id=seq1', 'reader');
        self::assertSame('hidden', $grid['data']['rows'][0]['cells'][$anonymous]['state']);

        [$denied] = $this->api('POST', '/assessments/' . $anonymous . '/reveal', 'teacher');
        [$revealed] = $this->api('POST', '/assessments/' . $anonymous . '/reveal', 'censor');
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame(200, $revealed->getStatusCode());
        [, $after] = $this->api('GET', '/grade-sheets/' . $anonymous, 'reader');
        self::assertNotNull($after['data']['rows'][0]['name']);
    }

    public function testEvaluationQualitativeParCompetences(): void
    {
        $qualitative = $this->assessment($this->math, 'Compétence : résoudre un problème', 1.0, 20.0, ['grading_mode' => 'qualitative']);

        $grade = $this->grade($qualitative, 'p1', ['qualitative' => 'in_progress']);
        [$wrong] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $qualitative, 'person_id' => 'p2', 'value' => 12]);
        [$unknown] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $qualitative, 'person_id' => 'p2', 'qualitative' => 'excellent']);

        self::assertSame('in_progress', $grade['qualitative']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertSame(422, $unknown->getStatusCode());
    }

    public function testReductionDuBaremeSousUneNoteDejaSaisieEstRefusee(): void
    {
        $this->grade($this->devoir, 'p1', ['value' => 18]);
        [$response] = $this->api('PATCH', '/assessments/' . $this->devoir, 'censor', ['max_score' => 10]);

        self::assertSame(409, $response->getStatusCode());
    }
}
