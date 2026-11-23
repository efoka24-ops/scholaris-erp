<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Grades;

use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Grades\Console\GradingRemindersCommand;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradeBook;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Higher\Grades\GradesTestCase;

/** EX-NOT-08 (suivi, relances NOT-11), EX-NOT-09 (statistiques par EC), contrat GradeBook et sonde RG-MAQ-1. */
final class GradingFollowUpTest extends GradesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGrades();
    }

    public function testStatistiquesParEvaluationEtParEc(): void
    {
        $cc = (string) $this->assessment('cc')['id'];
        $this->enter($cc, ['p1' => 8.0, 'p2' => 14.0, 'p3' => 'ABJ']);
        $exam = (string) $this->assessment()['id'];
        $this->enter($exam, ['p1' => 6.5, 'p2' => 12.0, 'p3' => 'ABI']);

        $stats = $this->ok('GET', '/higher/grade-assessments/' . $exam . '/statistics', null, self::CHEF);
        self::assertSame(3, $stats['statistics']['count'], 'ABI compte 0.');
        self::assertEquals([6.17, 0.0, 12.0], [$stats['statistics']['mean'], $stats['statistics']['min'], $stats['statistics']['max']]);
        self::assertEquals(33.33, $stats['statistics']['pass_rate']);
        self::assertSame(['unjustified' => 1], $stats['absences']);

        $ec = $this->ok('GET', '/higher/grades/elements/' . $this->curriculum['elements']['MF'] . '/statistics', null, self::TEACHER);
        self::assertSame(3, $ec['students']);
        self::assertEquals([7.1, 12.8], [$ec['statistics']['min'], $ec['statistics']['max']]);
        self::assertSame(2, $ec['statistics']['count'], 'p3 : CC exclu (ABJ à repasser) mais examen ABI = 0 → 0.');
        self::assertSame(404, $this->call('GET', '/higher/grades/elements/' . $this->curriculum['elements']['MF'] . '/statistics', null, self::OTHER_TEACHER)[0]);
    }

    public function testSuiviDeLaSaisieEtRelancesNot11(): void
    {
        $late = (string) $this->assessment('cc', ['entry_due_on' => '2026-10-10'])['id'];
        $this->assessment('exam', ['entry_due_on' => '2026-12-20']);
        $this->enter($late, ['p1' => 10.0]);

        $progress = $this->ok('GET', '/higher/grades/progress', null, self::CHEF);
        self::assertSame(['assessments' => 2, 'complete' => 0, 'overdue' => 1, 'by_status' => ['draft' => 2]], $progress['summary']);

        $command = $this->service(GradingRemindersCommand::class);
        ob_start();
        self::assertSame(0, $command->run(['2026-10-13']));
        ob_end_clean();
        self::assertSame([['NOT-11', 'pt1']], array_map(static fn (array $n): array => [$n['code'], $n['person_id']], $this->fakes->notifications));
        ob_start();
        $command->run(['2026-10-14']);
        $command->run(['2026-10-16']);
        ob_end_clean();
        self::assertCount(2, $this->fakes->notifications, 'Relance tous les 3 jours.');

        $this->applications->set(self::T, ApplicationAccess::SUSPENDED);
        ob_start();
        $command->run(['2026-10-25']);
        ob_end_clean();
        self::assertCount(2, $this->fakes->notifications, 'Client suspendu sauté.');
        self::assertSame(2, $command->run(['pas-une-date']));
    }

    public function testCarnetDeNotesPourLaDeliberationEtSondeDeLaMaquette(): void
    {
        $book = $this->service(GradeBook::class);
        $a = $this->lockedAssessment();
        $open = (string) $this->assessment('cc')['id'];

        self::assertCount(2, $book->assessmentsOf(self::T, self::E, $this->curriculum['id'], 1));
        self::assertFalse($book->allLocked(self::T, self::E, $this->curriculum['id']), 'Un CC encore en brouillon (RG-DEL-1).');
        self::assertFalse($book->elementComplete(self::T, self::E, $this->curriculum['elements']['MF']));
        self::assertCount(3, $book->marksOf(self::T, self::E, (string) $a['id']));
        self::assertTrue($this->service(CurriculumDirectory::class)->find(self::T, self::E, $this->curriculum['id'])?->gradesRecorded);
        unset($open);
        self::assertSame(0, $book->assessmentsOf(self::TB, self::EB, $this->curriculum['id']) === [] ? 0 : 1, 'Autre client : rien.');
    }
}
