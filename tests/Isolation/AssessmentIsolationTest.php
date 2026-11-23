<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Assessment\AssessmentTestCase;

/** Cloisonnement du module Assessment : par client (tenant) et par établissement. */
final class AssessmentIsolationTest extends AssessmentTestCase
{
    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->seedClass();
        $class = $this->gradedClass(true);
        $this->api('POST', '/calculation-rules', 'censor', ['academic_year_id' => 'ay1', 'label' => 'v1', 'definition' => ['rounding' => 1]]);
        [, $council] = $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'seq1']);
        [, $deliberation] = $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'president_id' => 'm1', 'members_expected' => 3]);
        $gradeId = (string) $this->rows('SELECT id FROM grades LIMIT 1')[0]['id'];
        [, $correction] = $this->api('POST', '/grade-corrections', 'censor', ['grade_id' => $gradeId, 'value' => 1, 'reason' => 'test']);

        $this->ids = [
            'subject' => $class['math'], 'assessment' => $class['math_devoir'], 'grade' => $gradeId, 'council' => $council['data']['id'],
            'deliberation' => $deliberation['data']['id'], 'correction' => $correction['data']['id'],
            'result' => (string) $this->rows('SELECT id FROM results LIMIT 1')[0]['id'], 'rules' => (string) $this->rows('SELECT id FROM calculation_rules LIMIT 1')[0]['id'],
        ];

        // Un utilisateur du client t2 (établissement x1) et un utilisateur de e2 (même client, autre établissement).
        foreach (['spy' => 'x1', 'local' => 'e2'] as $user => $establishment) {
            $this->access->grant($user, $establishment);
            $this->permissions->grant($user, 'assessment.read', 'assessment.enter', 'assessment.validate', 'assessment.publish', 'assessment.correction.approve', 'assessment.council.manage', 'assessment.deliberation.manage');
        }
    }

    /** @return list<string> */
    private function itemPaths(): array
    {
        return [
            '/subjects/' . $this->ids['subject'], '/assessments/' . $this->ids['assessment'], '/grades/' . $this->ids['grade'], '/grades/' . $this->ids['grade'] . '/events',
            '/grade-sheets/' . $this->ids['assessment'], '/grade-corrections/' . $this->ids['correction'], '/class-councils/' . $this->ids['council'],
            '/class-councils/' . $this->ids['council'] . '/report-cards', '/deliberations/' . $this->ids['deliberation'], '/results/' . $this->ids['result'],
            '/calculation-rules/' . $this->ids['rules'],
        ];
    }

    public function testUnAutreClientNeVoitRienEtNeModifieRien(): void
    {
        foreach ($this->itemPaths() as $path) {
            [$response] = $this->api('GET', $path, 'spy', null, 't2', 'x1');
            self::assertSame(404, $response->getStatusCode(), $path);
        }
        foreach (['/subjects', '/assessments', '/grades', '/grade-corrections', '/class-councils', '/deliberations', '/results', '/calculation-rules'] as $path) {
            [, $json] = $this->api('GET', $path, 'spy', null, 't2', 'x1');
            self::assertSame([], $json['data'], $path);
        }

        $writes = [
            ['POST', '/grades', ['assessment_id' => $this->ids['assessment'], 'person_id' => 'p1', 'value' => 1]],
            ['POST', '/grades/batch', ['assessment_id' => $this->ids['assessment'], 'entries' => [['person_id' => 'p1', 'value' => 1]]]],
            ['POST', '/assessments/' . $this->ids['assessment'] . '/transition', ['to' => 'published']],
            ['POST', '/grade-corrections/' . $this->ids['correction'] . '/approve', []],
            ['POST', '/class-councils/' . $this->ids['council'] . '/validate', []],
            ['POST', '/deliberations/' . $this->ids['deliberation'] . '/start', []],
            ['PATCH', '/subjects/' . $this->ids['subject'], ['name' => 'Piraté']],
        ];
        foreach ($writes as [$method, $path, $body]) {
            [$response] = $this->api($method, $path, 'spy', $body, 't2', 'x1');
            self::assertContains($response->getStatusCode(), [404, 422], $method . ' ' . $path);
        }
        [$import] = [$this->upload('/grades/import?assessment_id=' . $this->ids['assessment'], 'spy', "matricule;nom;note\nMAT-P1;A;1\n", 'x1')];
        self::assertSame(404, $import->getStatusCode(), 'L\'import n\'atteint pas l\'épreuve d\'un autre client.');

        self::assertSame(16.0, (float) $this->rows("SELECT score FROM grades WHERE person_id = 'p1' AND assessment_id = '" . $this->ids['assessment'] . "'")[0]['score']);
        self::assertSame('locked', $this->rows("SELECT status FROM assessments WHERE id = '" . $this->ids['assessment'] . "'")[0]['status']);
    }

    public function testUnAutreEtablissementDuMemeClientNeVoitRienEtNeModifieRien(): void
    {
        foreach ($this->itemPaths() as $path) {
            [$response] = $this->api('GET', $path, 'local', null, 't1', 'e2');
            self::assertSame(404, $response->getStatusCode(), $path);
        }
        foreach (['/subjects', '/assessments', '/grades', '/grade-corrections', '/class-councils', '/deliberations', '/results', '/calculation-rules'] as $path) {
            [, $json] = $this->api('GET', $path, 'local', null, 't1', 'e2');
            self::assertSame([], $json['data'], $path);
        }

        [, $grid] = $this->api('GET', '/grade-sheets?structure_id=c1&period_id=seq1', 'local', null, 't1', 'e2');
        self::assertSame([], $grid['data']['rows'], 'Les élèves de c1 appartiennent à e1 : aucun pour e2.');
        self::assertSame([], $grid['data']['columns']);

        [$grade] = $this->api('POST', '/grades', 'local', ['assessment_id' => $this->ids['assessment'], 'person_id' => 'p1', 'value' => 1], 't1', 'e2');
        [$council] = $this->api('POST', '/class-councils', 'local', ['structure_id' => 'c1', 'period_id' => 'seq1'], 't1', 'e2');
        [$publish] = $this->api('POST', '/results/publish', 'local', ['structure_id' => 'c1', 'period_id' => 'seq1'], 't1', 'e2');
        self::assertSame(404, $grade->getStatusCode());
        self::assertSame(422, $council->getStatusCode(), 'Aucun élève de c1 dans e2.');
        self::assertSame(404, $publish->getStatusCode());
        self::assertSame(16.0, (float) $this->rows("SELECT score FROM grades WHERE person_id = 'p1' AND assessment_id = '" . $this->ids['assessment'] . "'")[0]['score']);
    }

    public function testUnIdentifiantDeClasseDUnAutreEtablissementNeLivreAucunEleve(): void
    {
        [$stats] = $this->api('GET', '/sequence-statistics?structure_id=c1&period_id=seq1', 'local', null, 't1', 'e2');
        $json = $this->decode($stats);

        self::assertSame(200, $stats->getStatusCode());
        self::assertSame(0, $json['data']['sequence']['students']);
    }
}
