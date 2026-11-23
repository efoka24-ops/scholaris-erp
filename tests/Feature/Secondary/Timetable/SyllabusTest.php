<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Timetable;

use Scholaris\Modules\Apps\Secondary\Syllabus\Application\CoverageService;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageReader;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;

/**
 * T862, T863 : progressions, cahier de textes signé (API-06, hors ligne par client_uuid), séance d'une classe non affectée 403
 * journalisée, couverture heures faites / heures prévues exacte sur un jeu d'exemple, visas, DOC-25, DOC-26, lecture de
 * l'inspecteur (périmètre territorial, journalisée), rappel NOT-12 une fois par jour. Données fictives.
 */
final class SyllabusTest extends TimetableWorld
{
    private string $school;
    private string $classA;
    private string $classB;
    private string $math;

    protected function setUp(): void
    {
        parent::setUp();
        $w = $this->world(2, ['MATH' => 4]);
        [$this->school, $this->classA, $this->classB, $this->math] = [$w['school'], $w['classes'][0], $w['classes'][1], $w['subjects']['MATH']];
        $this->assign($this->school, $this->classA, $this->math, $this->teacher('prof-1'));
        $this->assign($this->school, $this->classB, $this->math, $this->teacher('prof-2'));
        $this->grant(self::TA, 'animateur', $this->school, ['secondary.syllabus.manage']);
        $this->grant(self::TA, 'u-prof-1', $this->school, ['secondary.lesson.log'], Scope::CLASS_GROUP, $this->classA);
        $this->link(self::TA, 'u-prof-1', 'prof-1');
    }

    /** @return array<string, mixed> */
    private function progression(): array
    {
        $lessons = [];
        for ($i = 1; $i <= 4; ++$i) {
            $lessons[] = ['title' => 'Leçon ' . $i, 'planned_hours' => 2.5, 'planned_week' => $i * 2];
        }

        return $this->ok('POST', '/secondary/syllabus/progressions', ['academic_year_id' => self::YEAR, 'subject_ref' => $this->math, 'level_code' => '6E', 'lessons' => $lessons], 'animateur', self::TA, $this->school);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function log(string $uuid, float $hours, string $date, array $extra = [], string $user = 'u-prof-1', ?string $class = null): array
    {
        return $this->call('POST', '/secondary/lesson-logs', $extra + [
            'client_uuid' => $uuid, 'class_id' => $class ?? $this->classA, 'subject_ref' => $this->math, 'session_date' => $date, 'hours' => $hours,
            'content' => 'Contenu fictif de la séance', 'homework' => 'Exercices 1 à 3',
        ], $user, self::TA, $this->school);
    }

    public function testCahierSigneHorsLigneEtCouvertureExacte(): void
    {
        $progression = $this->progression();
        self::assertEquals(10.0, $progression['total_hours']);
        self::assertSame(409, $this->call('POST', '/secondary/syllabus/progressions', ['academic_year_id' => self::YEAR, 'subject_ref' => $this->math, 'level_code' => '6E', 'lessons' => [['title' => 'x', 'planned_hours' => 1, 'planned_week' => 1]]], 'animateur', self::TA, $this->school)[0]);
        [$l1, $l2] = array_column($progression['lessons'], 'id');
        [$status, $first] = $this->log('hors-ligne-0001', 2, '2026-10-12', ['lesson_id' => $l1]);
        self::assertSame(201, $status);
        self::assertSame(64, strlen($first['data']['signature_hash']), 'Séance signée électroniquement.');
        [$status, $replay] = $this->log('hors-ligne-0001', 2, '2026-10-12', ['lesson_id' => $l1]);
        self::assertSame(200, $status, 'Rejeu hors ligne : la séance existante est rendue.');
        self::assertTrue($replay['data']['replayed']);
        self::assertSame(201, $this->log('hors-ligne-0002', 1.5, '2026-10-14', ['lesson_id' => $l2])[0]);
        self::assertSame(201, $this->log('hors-ligne-0003', 0.75, '2026-10-20', ['lesson_id' => $l2])[0]);
        self::assertSame(3, (int) $this->q('SELECT COUNT(*) FROM se_lesson_logs')->fetchColumn());
        self::assertSame(422, $this->log('hors-ligne-0004', 1, '2026-10-30')[0], 'Séance future.');
        self::assertSame(422, $this->log('hors-ligne-0005', 0.3, '2026-10-20')[0], 'Heures au quart d\'heure.');

        $coverage = $this->ok('GET', '/secondary/syllabus/coverage?academic_year_id=' . self::YEAR . '&class_id=' . $this->classA . '&year_start=2026-09-07', null, self::ADMIN, self::TA, $this->school);
        $line = $coverage['lines'][0];
        self::assertEquals(10.0, $line['planned_hours']);
        self::assertEquals(4.25, $line['done_hours']);
        self::assertEquals(0.425, $line['rate'], 'Heures faites / heures prévues.');
        self::assertEquals(42.5, $line['rate_percent']);
        self::assertSame(2, $line['lessons_done']);
        self::assertSame(4, $line['lessons_total']);
        self::assertSame(7, $coverage['week'], 'Semaine 7 au 23/10 pour une rentrée le 07/09.');
        self::assertEquals(7.5, $line['expected_hours_to_date'], 'Leçons prévues semaines 2, 4 et 6.');
        $lines = $this->service(CoverageReader::class)->coverage(self::TA, $this->school, self::YEAR, $this->classA);
        self::assertEquals(0.425, $lines[0]->rate);
        self::assertSame(409, $this->call('PUT', '/secondary/syllabus/progressions/' . $progression['id'] . '/lessons', ['lessons' => [['title' => 'Autre', 'planned_hours' => 1, 'planned_week' => 1]]], 'animateur', self::TA, $this->school)[0], 'Leçons déjà traitées.');
        self::assertSame('approved', $this->ok('POST', '/secondary/syllabus/progressions/' . $progression['id'] . '/approve', [], 'animateur', self::TA, $this->school)['status']);

        $visa = $this->ok('POST', '/secondary/syllabus/visas', ['academic_year_id' => self::YEAR, 'class_id' => $this->classA, 'subject_ref' => $this->math, 'remark' => 'Vu'], self::ADMIN, self::TA, $this->school);
        self::assertSame('censor', $visa['visa_role']);
        self::assertSame('head_of_dept', $this->ok('POST', '/secondary/syllabus/visas', ['academic_year_id' => self::YEAR, 'class_id' => $this->classA, 'subject_ref' => $this->math], 'animateur', self::TA, $this->school)['visa_role']);
        $book = $this->ok('GET', '/secondary/syllabus/logbook?academic_year_id=' . self::YEAR . '&class_id=' . $this->classA, null, self::ADMIN, self::TA, $this->school);
        self::assertSame('DOC-25', $book['doc']);
        self::assertCount(3, $book['entries']);
        self::assertCount(2, $book['visas']);
        self::assertSame('Prof Prof-1', $book['entries'][0]['teacher_name']);
        $report = $this->ok('GET', '/secondary/syllabus/coverage/report?academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $this->school);
        self::assertSame('DOC-26', $report['doc']);
        self::assertEquals(0.2125, $report['rate'], 'Deux classes du même niveau : (4,25 + 0) / (10 + 10).');
    }

    public function testSeanceDUneClasseNonAffecteeRefuseeEtJournalisee(): void
    {
        [$status] = $this->log('hors-ligne-0101', 1, '2026-10-20', [], 'u-prof-1', $this->classB);
        self::assertSame(403, $status, 'Classe hors périmètre.');
        self::assertContains('secondary.scope.denied', $this->audit->actions());
        $this->grant(self::TA, 'u-prof-3', $this->school, ['secondary.lesson.log']);
        $this->link(self::TA, 'u-prof-3', $this->teacher('prof-3'));
        [$status, $problem] = $this->log('hors-ligne-0102', 1, '2026-10-20', [], 'u-prof-3');
        self::assertSame(403, $status, 'Enseignant non affecté à la classe.');
        self::assertStringContainsString('affecté', (string) $problem['detail']);
        self::assertContains('secondary.lesson.denied', $this->audit->actions());
        self::assertSame(0, (int) $this->q('SELECT COUNT(*) FROM se_lesson_logs')->fetchColumn());
        self::assertSame(403, $this->call('POST', '/secondary/syllabus/progressions', ['academic_year_id' => self::YEAR], 'u-prof-1', self::TA, $this->school)[0]);
    }

    public function testInspecteurLitParSonPerimetreTerritorialEtChaqueLectureEstJournalisee(): void
    {
        $this->progression();
        $this->log('hors-ligne-0201', 2, '2026-10-12');
        $this->permissions->grant(self::TA, 'inspecteur', 'secondary.inspection.read', $this->school);
        $this->access->grant('inspecteur', $this->school);
        $this->scopes->add(self::TA, 'inspecteur', $this->school, 'secondary.inspection.read', Scope::territory('arrondissement-1', 'sec_inspecteur', self::TA, $this->school));
        $before = count(array_filter($this->audit->actions(), static fn (string $a): bool => $a === 'secondary.inspection.read'));
        $book = $this->ok('GET', '/secondary/syllabus/logbook?academic_year_id=' . self::YEAR . '&class_id=' . $this->classA, null, 'inspecteur', self::TA, $this->school);
        self::assertCount(1, $book['entries']);
        $this->ok('GET', '/secondary/syllabus/progressions', null, 'inspecteur', self::TA, $this->school);
        $after = count(array_filter($this->audit->actions(), static fn (string $a): bool => $a === 'secondary.inspection.read'));
        self::assertSame($before + 2, $after, 'Chaque lecture de l\'inspecteur est journalisée.');
        self::assertSame(403, $this->log('hors-ligne-0202', 1, '2026-10-20', [], 'inspecteur')[0], 'Lecture seule.');
        self::assertSame(403, $this->call('POST', '/secondary/syllabus/visas', ['academic_year_id' => self::YEAR], 'inspecteur', self::TA, $this->school)[0]);

        $other = $this->factory->opened(self::TA, 'ces');
        $this->permissions->grant(self::TA, 'inspecteur', 'secondary.inspection.read', $other);
        $this->access->grant('inspecteur', $other);
        self::assertSame(403, $this->call('GET', '/secondary/syllabus/progressions', null, 'inspecteur', self::TA, $other)[0], 'Établissement non visité.');
    }

    public function testRappelNot12UneFoisParJourAuxEnseignantsEnRetard(): void
    {
        $assignments = array_column($this->ok('GET', '/secondary/timetable/assignments', null, self::ADMIN, self::TA, $this->school), 'id', 'teacher_person_id');
        $this->pose($this->school, $assignments['prof-1'], 1, '08:00', '09:00');
        $this->pose($this->school, $assignments['prof-2'], 2, '08:00', '09:00');
        $this->log('hors-ligne-0301', 1, '2026-10-20');
        $result = $this->ok('POST', '/secondary/syllabus/reminders', ['academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school);
        self::assertSame(7, $result['reminder_days']);
        self::assertSame(['prof-2'], array_column($result['late'], 'teacher_person_id'));
        self::assertSame(46, $result['late'][0]['days']);
        self::assertSame(1, $result['notified']);
        $sent = $this->messenger->of(CoverageService::TEMPLATE_NOT12);
        self::assertSame('prof-2', $sent[0]['person']);
        self::assertSame(['offset', 'subject', 'class_name'], array_keys($sent[0]['variables']));
        self::assertSame(0, $this->ok('POST', '/secondary/syllabus/reminders', ['academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school)['notified'], 'Une fois par jour.');
        self::assertSame(403, $this->call('POST', '/secondary/syllabus/reminders', ['academic_year_id' => self::YEAR], 'u-prof-1', self::TA, $this->school)[0]);
    }
}
