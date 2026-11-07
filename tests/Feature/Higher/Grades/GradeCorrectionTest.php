<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Grades;

use Tests\Support\Higher\Grades\GradesTestCase;

/** EX-NOT-06, RG-NOT-2 : correction motivée avec pièce, double validation chef de département puis directeur d'école. */
final class GradeCorrectionTest extends GradesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGrades();
    }

    /** @return array<string, mixed> */
    private function requestFor(string $assessmentId, float $score = 11.0): array
    {
        return $this->ok('POST', '/higher/grade-marks/' . $this->line($assessmentId, 'p1')['mark_id'] . '/changes', [
            'score' => $score, 'reason' => 'Erreur de report de la copie n° 12.', 'attachment_ref' => 'ged://copies/mf-12.pdf',
        ], self::TEACHER);
    }

    public function testDoubleValidationPuisNouvelleVersionLiee(): void
    {
        $a = (string) $this->lockedAssessment(8.0)['id'];
        $correction = $this->requestFor($a);
        self::assertSame(['pending', 0, 'Chef de département'], [$correction['status'], $correction['approval_step'], $correction['next_step']['label']]);

        self::assertSame(403, $this->call('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::DIRECTOR)[0], 'Étape du chef de département.');
        $step1 = $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::CHEF);
        self::assertSame(['pending', 1], [$step1['status'], $step1['approval_step']]);
        self::assertEquals(8.0, $this->line($a, 'p1')['score'], 'Pas encore appliquée.');

        [$status, $problem] = $this->call('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::DIRECTOR);
        self::assertSame([403, 'https://api.scholaris.cm/errors/second-factor-required'], [$status, $problem['type']]);
        $this->fakes->recent2fa[self::T . ':' . self::DIRECTOR] = true;
        $done = $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::DIRECTOR);

        self::assertSame(['approved', 2], [$done['status'], $done['approval_step']]);
        $line = $this->line($a, 'p1');
        self::assertEquals([11.0, 2], [$line['score'], $line['version']]);
        $events = $this->ok('GET', '/higher/grade-marks/' . $line['mark_id'] . '/events', null, self::CHEF);
        self::assertSame(['entry', 'correction'], array_column($events, 'kind'));
        self::assertSame($correction['id'], $events[1]['correction_id']);
        self::assertEquals(8.0, $events[1]['old']['score']);
    }

    public function testLeDemandeurNeValideJamaisEtUnValidateurNeValidePasDeuxEtapes(): void
    {
        $a = (string) $this->lockedAssessment()['id'];
        $correction = $this->requestFor($a);
        $this->assign(self::T, self::TEACHER, self::E, 'assessment.correction.approve', 'org_unit', $this->org['department'], 'higher_chef_dept');

        self::assertSame(403, $this->call('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::TEACHER)[0]);
        $this->assign(self::T, self::CHEF, self::E, 'assessment.correction.approve', 'org_unit', $this->org['school'], 'higher_dir_ecole');
        $this->fakes->recent2fa[self::T . ':' . self::CHEF] = true;
        $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::CHEF);
        [$status, $problem] = $this->call('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::CHEF);

        self::assertSame(403, $status);
        self::assertStringContainsString('déjà validé', (string) $problem['detail']);
    }

    public function testRejetEtDemandesRefusees(): void
    {
        $open = (string) $this->assessment('cc')['id'];
        $this->enter($open, ['p1' => 10.0]);
        self::assertSame(409, $this->call('POST', '/higher/grade-marks/' . $this->line($open, 'p1')['mark_id'] . '/changes', [
            'score' => 11.0, 'reason' => 'x', 'attachment_ref' => 'y',
        ], self::TEACHER)[0], 'Saisie ouverte : pas de correction.');

        $a = (string) $this->lockedAssessment()['id'];
        $markId = $this->line($a, 'p1')['mark_id'];
        self::assertSame(422, $this->call('POST', '/higher/grade-marks/' . $markId . '/changes', ['score' => 11.0, 'reason' => 'Sans pièce'], self::TEACHER)[0]);
        self::assertSame(422, $this->call('POST', '/higher/grade-marks/' . $markId . '/changes', ['score' => 6.3, 'reason' => 'r', 'attachment_ref' => 'p'], self::TEACHER)[0]);
        self::assertSame(403, $this->call('POST', '/higher/grade-marks/' . $markId . '/changes', ['score' => 11.0, 'reason' => 'r', 'attachment_ref' => 'p'], self::OTHER_TEACHER)[0]);
        $correction = $this->requestFor($a);
        self::assertSame(409, $this->call('POST', '/higher/grade-marks/' . $markId . '/changes', ['score' => 12.0, 'reason' => 'r', 'attachment_ref' => 'p'], self::TEACHER)[0], 'Une seule demande en cours.');

        $rejected = $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/reject', ['reason' => 'Copie conforme.'], self::CHEF);
        self::assertSame('rejected', $rejected['status']);
        self::assertEquals(8.0, $this->line($a, 'p1')['score']);
        self::assertCount(1, $this->ok('GET', '/higher/grade-corrections', null, self::TEACHER));
        self::assertSame([], $this->ok('GET', '/higher/grade-corrections', null, self::OTHER_TEACHER));
    }
}
