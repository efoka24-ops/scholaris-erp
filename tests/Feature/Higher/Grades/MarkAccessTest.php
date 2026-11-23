<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Grades;

use Tests\Support\Higher\Grades\GradesTestCase;

/** TST-03, RG-NOT-3 : un enseignant ne voit et ne saisit que ses EC et groupes ; toute tentative hors affectation est journalisée. */
final class MarkAccessTest extends GradesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGrades();
    }

    public function testTst03UnEnseignantNonAffecteRecoit403EtLaTentativeEstJournalisee(): void
    {
        $a = $this->assessment();
        [$status, $problem] = $this->enter((string) $a['id'], ['p1' => 12.0], self::OTHER_TEACHER);

        self::assertSame(403, $status, (string) json_encode($problem));
        $denied = array_values(array_filter($this->audit->entries, static fn (array $e): bool => $e['action'] === 'higher.grades.denied'));
        self::assertCount(1, $denied);
        self::assertSame([self::OTHER_TEACHER, (string) $a['id']], [$denied[0]['actor'], $denied[0]['id']]);
        self::assertNull($this->line((string) $a['id'], 'p1')['score'], 'Aucune note écrite.');
    }

    public function testLEnseignantNonAffecteNeVoitNiLaGrilleNiLEvaluation(): void
    {
        $a = $this->assessment();
        self::assertSame(404, $this->call('GET', '/higher/grade-assessments/' . $a['id'] . '/marks', null, self::OTHER_TEACHER)[0]);
        self::assertSame(404, $this->call('GET', '/higher/grade-assessments/' . $a['id'], null, self::OTHER_TEACHER)[0]);
        self::assertSame([], $this->ok('GET', '/higher/grade-assessments', null, self::OTHER_TEACHER));
        self::assertSame([(string) $a['id']], array_column($this->ok('GET', '/higher/grade-assessments', null, self::TEACHER), 'id'));
    }

    public function testLEnseignantNeCreePasDEvaluationSurUnEcNonAffecte(): void
    {
        [$status] = $this->call('POST', '/higher/grade-assessments', [
            'curriculum_id' => $this->curriculum['id'], 'element_id' => $this->curriculum['elements']['MF'], 'kind' => 'cc',
        ], self::OTHER_TEACHER);

        self::assertSame(403, $status);
    }

    public function testLEnseignantAffecteAUnSeulGroupeNeVoitQueCeGroupe(): void
    {
        $this->fakes->teacher(self::T, 'teacher-g2', 'pt3', $this->curriculum['elements']['MF'], $this->g2, self::E);
        $this->assign(self::T, 'teacher-g2', self::E, 'assessment.enter', 'structure', $this->g2, 'enseignant');
        $a = $this->assessment();
        $lines = $this->ok('GET', '/higher/grade-assessments/' . $a['id'] . '/marks', null, 'teacher-g2')['lines'];

        self::assertSame(['p3'], array_column($lines, 'person_id'));
        self::assertSame(422, $this->enter((string) $a['id'], ['p1' => 10.0], 'teacher-g2')[0], 'Étudiant d\'un autre groupe.');
        self::assertSame(200, $this->enter((string) $a['id'], ['p3' => 10.0], 'teacher-g2')[0]);
    }

    public function testLeChefDeDepartementLitDansSonPerimetreMaisNeSaisitPas(): void
    {
        $a = $this->assessment();
        self::assertCount(3, $this->ok('GET', '/higher/grade-assessments/' . $a['id'] . '/marks', null, self::CHEF)['lines']);
        self::assertSame(403, $this->enter((string) $a['id'], ['p1' => 12.0], self::CHEF)[0]);
    }

    public function testUnAutreClientNeVoitRienEtUnUtilisateurSansDroitEstRefuse(): void
    {
        $a = $this->assessment();
        self::assertSame(404, $this->call('GET', '/higher/grade-assessments/' . $a['id'], null, self::ADMIN_B, self::TB, self::EB)[0]);
        self::assertSame(403, $this->call('GET', '/higher/grade-assessments', null, 'nobody')[0]);
    }
}
