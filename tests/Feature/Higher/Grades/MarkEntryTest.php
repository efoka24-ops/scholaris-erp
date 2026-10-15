<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Grades;

use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Tests\Support\Higher\Grades\GradesTestCase;

/** Saisie (EX-NOT-01 à 03, API-09) : barème au quart de point (RG-NOT-1), codes d'absence, lot atomique, import CSV, hors ligne. */
final class MarkEntryTest extends GradesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGrades();
    }

    public function testEvaluationProjeteeSurLaMaquetteAvecPoidsParDefaut(): void
    {
        $exam = $this->assessment();
        $cc = $this->assessment('cc', ['group_id' => $this->org['g1'], 'held_on' => '2026-11-05', 'entry_due_on' => '2026-11-20']);

        self::assertSame(['MF', 1, 60, 20, 0.25, 'draft', 'normal'], [$exam['element_code'], $exam['semester_ordinal'], $exam['weight_percent'], $exam['max_score'], $exam['score_step'], $exam['status'], $exam['exam_session']]);
        self::assertSame([40, $this->org['g1']], [$cc['weight_percent'], $cc['group_id']]);
        self::assertSame('retake', $this->assessment('retake')['exam_session']);
        self::assertCount(2, $this->ok('GET', '/higher/grade-assessments/' . $cc['id'] . '/marks', null, self::TEACHER)['lines'], 'Groupe G1 seulement.');
        self::assertSame(422, $this->call('POST', '/higher/grade-assessments', ['curriculum_id' => $this->curriculum['id'], 'element_id' => 'inconnu', 'kind' => 'exam'], self::TEACHER)[0]);
    }

    public function testBaremeAuQuartDePointEtHorsBaremeRefuses(): void
    {
        $a = (string) $this->assessment()['id'];

        [$status, $problem] = $this->enter($a, ['p1' => 6.3]);
        self::assertSame(422, $status);
        self::assertStringContainsString('pas de 0,25', (string) json_encode($problem['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame(422, $this->enter($a, ['p1' => 21.0])[0], 'Hors barème.');
        self::assertSame(422, $this->enter($a, ['p1' => -1.0])[0]);
        self::assertSame(422, $this->enter($a, ['p1' => 12.0, 'p2' => 6.3])[0], 'Lot atomique : une ligne fausse, rien n\'est écrit.');
        self::assertNull($this->line($a, 'p1')['score']);

        self::assertSame(200, $this->enter($a, ['p1' => 12.75, 'p2' => 0.0, 'p3' => 20.0])[0]);
        self::assertSame([12.75, 1], [$this->line($a, 'p1')['score'], $this->line($a, 'p1')['version']]);
    }

    public function testCodesDAbsenceEtFraudeSignaleeAuConseilDeDiscipline(): void
    {
        $a = (string) $this->assessment()['id'];
        [$status, $result] = $this->enter($a, ['p1' => 'ABJ', 'p2' => 'NA', 'p3' => 'FR']);

        self::assertSame(200, $status, (string) json_encode($result));
        self::assertSame(1, $result['data']['discipline_referrals']);
        self::assertSame(['justified', 'na', 'fr'], [$this->line($a, 'p1')['absence'], $this->line($a, 'p2')['absence'], $this->line($a, 'p3')['absence']]);
        self::assertContains('higher.grades.fraud_referral', array_column($this->audit->entries, 'action'));
        self::assertSame(422, $this->enter($a, ['p1' => 'XYZ'])[0]);
        self::assertSame(200, $this->enter($a, ['p1' => 'ABI'])[0]);
        self::assertSame('unjustified', $this->line($a, 'p1')['absence']);
    }

    public function testConflitDeVersionSignaleJamaisEcrase(): void
    {
        $a = (string) $this->assessment()['id'];
        $this->enter($a, ['p1' => 10.0]);
        [$status] = $this->call('PUT', '/higher/grade-assessments/' . $a . '/marks', ['marks' => [['person_id' => 'p1', 'score' => 14.0, 'expected_version' => 0]]], self::TEACHER);

        self::assertSame(409, $status);
        self::assertEquals(10.0, $this->line($a, 'p1')['score']);
    }

    public function testSynchronisationHorsLigneSansDoublonEtConflitSignale(): void
    {
        $a = (string) $this->assessment()['id'];
        $entries = [
            ['client_uuid' => 'dev1-0001', 'assessment_id' => $a, 'person_id' => 'p1', 'base_version' => 0, 'score' => 11.5],
            ['client_uuid' => 'dev1-0002', 'assessment_id' => $a, 'person_id' => 'p2', 'base_version' => 0, 'absence' => 'ABJ'],
        ];
        $first = $this->call('POST', '/higher/grades/sync', ['entries' => $entries], self::TEACHER)[1]['data'];
        $replay = $this->call('POST', '/higher/grades/sync', ['entries' => $entries], self::TEACHER)[1]['data'];
        $stale = $this->call('POST', '/higher/grades/sync', ['entries' => [['client_uuid' => 'dev2-0001', 'assessment_id' => $a, 'person_id' => 'p1', 'base_version' => 0, 'score' => 3.0]]], self::TEACHER)[1]['data'];

        self::assertSame(['applied', 'applied'], array_column($first, 'status'));
        self::assertSame(['duplicate', 'duplicate'], array_column($replay, 'status'));
        self::assertSame('conflict', $stale[0]['status']);
        self::assertSame(11.5, $stale[0]['server']['score']);
        self::assertSame(11.5, $this->line($a, 'p1')['score']);
    }

    public function testImportCsvAuFormatDuModele(): void
    {
        $a = (string) $this->assessment()['id'];
        $template = (string) $this->kernel->handle(new \Nyholm\Psr7\ServerRequest('GET', '/api/v1/higher/grade-assessments/' . $a . '/marks/template', [
            'Authorization' => 'Bearer ' . self::TEACHER . ':' . self::T, 'X-Establishment-Id' => self::E,
        ]))->getBody();
        self::assertStringContainsString('matricule;nom;prenom;note', $template);

        $csv = "matricule;nom;prenom;note\n26EC-CF-0001;N.;Marie;8\n26EC-CF-0002;A.;B.;12,5\n26EC-CF-0003;C.;D.;ABJ\n";
        $result = $this->ok('POST', '/higher/grade-assessments/' . $a . '/marks/import', ['content' => $csv], self::TEACHER);

        self::assertSame([3, 3], [$result['lines'], $result['written']]);
        self::assertSame([12.5, 'justified'], [$this->line($a, 'p2')['score'], $this->line($a, 'p3')['absence']]);
        self::assertSame(422, $this->call('POST', '/higher/grade-assessments/' . $a . '/marks/import', ['content' => "matricule;note\nINCONNU;10\n"], self::TEACHER)[0]);
    }

    public function testLaMaquetteEstMarqueeAvecNotesRgMaq1(): void
    {
        $a = (string) $this->assessment()['id'];
        self::assertFalse($this->service(CurriculumDirectory::class)->find(self::T, self::E, $this->curriculum['id'])?->gradesRecorded);
        $this->enter($a, ['p1' => 10.0]);

        self::assertTrue($this->service(CurriculumDirectory::class)->find(self::T, self::E, $this->curriculum['id'])?->gradesRecorded);
        self::assertSame(1, count($this->ok('GET', '/higher/grade-marks/' . $this->line($a, 'p1')['mark_id'] . '/events', null, self::TEACHER)));
    }

    public function testConfigurationIpesDuMoteur(): void
    {
        $settings = $this->ok('GET', '/higher/grades/settings', null, self::CHEF);
        self::assertSame(0.25, $settings['assessment.step']);
        self::assertSame(['justified', 'unjustified', 'na', 'fr'], array_keys($settings['assessment.absence_codes']));
        self::assertSame(['draft', 'submitted', 'validated', 'locked', 'published'], $settings['assessment.lifecycle']['states']);
        self::assertSame(['locked'], $settings['assessment.segregate_lock']);

        $this->settings->set(self::E, 'assessment.step', 0.5);
        self::assertSame(422, $this->enter((string) $this->assessment('cc')['id'], ['p1' => 12.25])[0], 'Pas de l\'établissement : 0,5.');
    }
}
