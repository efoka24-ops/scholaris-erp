<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Grades;

use Tests\Support\Higher\Grades\GradesTestCase;

/** EX-NOT-05 : brouillon → soumise → validée → verrouillée (2FA) → publiée ; TST-06 ; séparation des tâches ; anonymat. */
final class GradeLifecycleTest extends GradesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGrades();
    }

    public function testCycleCompletAvecDoubleAuthentificationEtGardeDePublication(): void
    {
        $a = (string) $this->assessment()['id'];
        $this->enter($a, ['p1' => 8.0, 'p2' => 12.5]);
        self::assertSame(422, $this->call('POST', '/higher/grade-assessments/' . $a . '/submit', null, self::TEACHER)[0], 'Cellule vide.');
        $this->enter($a, ['p3' => 'ABI']);

        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a . '/submit', null, self::OTHER_TEACHER)[0]);
        self::assertSame('submitted', $this->ok('POST', '/higher/grade-assessments/' . $a . '/submit', null, self::TEACHER)['status']);
        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::TEACHER)[0], 'L\'enseignant ne valide pas.');
        self::assertSame('validated', $this->ok('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::CHEF)['status']);

        [$status, $problem] = $this->call('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::CHEF);
        self::assertSame(403, $status);
        self::assertSame('https://api.scholaris.cm/errors/second-factor-required', $problem['type']);
        $this->fakes->recent2fa[self::T . ':' . self::CHEF] = true;
        self::assertSame('locked', $this->ok('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::CHEF)['status']);

        $this->fakes->recent2fa[self::T . ':' . self::REGISTRAR] = true;
        $this->fakes->publicationRefusal = 'Procès-verbal de délibération non signé.';
        [$status, $problem] = $this->call('POST', '/higher/grade-assessments/' . $a . '/publish', null, self::REGISTRAR);
        self::assertSame([409, 'Procès-verbal de délibération non signé.'], [$status, $problem['detail']]);
        $this->fakes->publicationRefusal = null;
        $published = $this->ok('POST', '/higher/grade-assessments/' . $a . '/publish', null, self::REGISTRAR);
        self::assertSame(['published', ['submitted', 'validated', 'locked', 'published']], [$published['status'], array_column($published['status_trail'], 'to')]);
    }

    public function testRenvoiALEnseignantPuisNouvelleSaisie(): void
    {
        $a = (string) $this->assessment()['id'];
        $this->enter($a, ['p1' => 8.0, 'p2' => 12.5, 'p3' => 10.0]);
        $this->ok('POST', '/higher/grade-assessments/' . $a . '/submit', null, self::TEACHER);
        self::assertSame(409, $this->enter($a, ['p1' => 9.0])[0], 'Soumise : saisie fermée.');
        self::assertSame(422, $this->call('POST', '/higher/grade-assessments/' . $a . '/send-back', [], self::CHEF)[0], 'Renvoi sans motif refusé.');
        $back = $this->ok('POST', '/higher/grade-assessments/' . $a . '/send-back', ['reason' => 'Note de p2 à vérifier sur la copie.'], self::CHEF);
        self::assertSame('draft', $back['status']);
        self::assertSame('Note de p2 à vérifier sur la copie.', end($back['status_trail'])['reason'] ?? null, 'Motif gardé dans l\'historique.');
        self::assertSame(200, $this->enter($a, ['p1' => 9.0])[0]);
    }

    public function testTst06ModifierUneNoteVerrouilleeSansProcedureEstRefuseEtLaNoteResteInchangee(): void
    {
        $a = (string) $this->lockedAssessment()['id'];
        $before = $this->line($a, 'p1');

        [$status] = $this->enter($a, ['p1' => 15.0]);
        $sync = $this->call('POST', '/higher/grades/sync', ['entries' => [['client_uuid' => 'dev9-0001', 'assessment_id' => $a, 'person_id' => 'p1', 'base_version' => 1, 'score' => 15.0]]], self::TEACHER)[1]['data'];
        $import = $this->call('POST', '/higher/grade-assessments/' . $a . '/marks/import', ['content' => "matricule;note\n26EC-CF-0001;15\n"], self::TEACHER)[0];

        self::assertSame([409, 'rejected', 409], [$status, $sync[0]['status'], $import]);
        self::assertSame($before, $this->line($a, 'p1'));
        self::assertEquals(8.0, $before['score']);
        self::assertContains('higher.grades.locked_write_refused', array_column($this->audit->entries, 'action'));
    }

    public function testSeparationDesTachesQuiASaisiNeVerrouillePas(): void
    {
        $this->fakes->teacher(self::T, self::CHEF, 'pchef', $this->curriculum['elements']['MF'], null, self::E);
        $this->assign(self::T, self::CHEF, self::E, 'assessment.enter', 'structure', $this->org['program'], 'enseignant');
        $this->assign(self::T, self::CHEF, self::E, 'higher.grades.submit', 'structure', $this->org['program'], 'enseignant');
        $a = (string) $this->assessment('exam', [], 'MF', self::CHEF)['id'];
        $this->enter($a, ['p1' => 8.0, 'p2' => 12.5, 'p3' => 10.0], self::CHEF);
        $this->ok('POST', '/higher/grade-assessments/' . $a . '/submit', null, self::CHEF);
        $this->ok('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::CHEF);
        $this->fakes->recent2fa[self::T . ':' . self::CHEF] = true;

        [$status, $problem] = $this->call('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::CHEF);
        self::assertSame(403, $status);
        self::assertStringContainsString('Séparation des tâches', (string) $problem['detail']);
    }

    public function testAnonymatSaisieSurNumerosPuisLeveeParLeServiceDesExamens(): void
    {
        $a = $this->assessment('exam', ['anonymous' => true]);
        $grid = $this->ok('GET', '/higher/grade-assessments/' . $a['id'] . '/marks', null, self::TEACHER);
        self::assertSame([null, null, null], array_column($grid['lines'], 'person_id'));
        $codes = array_column($grid['lines'], 'anonymous_code');
        self::assertCount(3, array_unique($codes));

        self::assertSame(422, $this->enter((string) $a['id'], ['p1' => 10.0])[0], 'Saisie sur l\'étudiant refusée.');
        $marks = array_map(static fn (string $c): array => ['anonymous_code' => $c, 'score' => 10.0], $codes);
        self::assertSame(200, $this->call('PUT', '/higher/grade-assessments/' . $a['id'] . '/marks', ['marks' => $marks], self::TEACHER)[0]);
        self::assertSame(409, $this->call('POST', '/higher/grade-assessments/' . $a['id'] . '/anonymity/lift', null, self::EXAMS)[0], 'Pendant la saisie.');
        $this->ok('POST', '/higher/grade-assessments/' . $a['id'] . '/submit', null, self::TEACHER);
        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a['id'] . '/anonymity/lift', null, self::CHEF)[0], 'Permission dédiée.');
        self::assertTrue($this->ok('POST', '/higher/grade-assessments/' . $a['id'] . '/anonymity/lift', null, self::EXAMS)['anonymity_lifted']);
        self::assertEqualsCanonicalizing(['p1', 'p2', 'p3'], array_column($this->ok('GET', '/higher/grade-assessments/' . $a['id'] . '/marks', null, self::CHEF)['lines'], 'person_id'));
        self::assertSame(422, $this->call('POST', '/higher/grade-assessments', ['curriculum_id' => $this->curriculum['id'], 'element_id' => $this->curriculum['elements']['MF'], 'kind' => 'cc', 'anonymous' => true], self::TEACHER)[0]);
    }

    public function testApplicationSuspendueLectureSeule(): void
    {
        $a = (string) $this->assessment()['id'];
        $this->applications->set(self::T, \Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess::SUSPENDED);

        self::assertSame(423, $this->enter($a, ['p1' => 10.0])[0]);
        self::assertSame(200, $this->call('GET', '/higher/grade-assessments/' . $a . '/marks', null, self::TEACHER)[0]);
    }
}
