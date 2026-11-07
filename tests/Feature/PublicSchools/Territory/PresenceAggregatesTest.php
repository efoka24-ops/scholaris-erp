<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Territory;

use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\PupilPresenceAggregates;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\TeacherPresenceAggregates;
use Scholaris\Modules\Apps\PublicSchools\Territory\Application\DashboardService;
use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * C-105 : indicateurs territoriaux de présence (élèves, enseignants) tirés des appels et des déclarations, sans aucun nom ; taux dérivés
 * du tableau de bord (présence, écoles qui remontent la présence = O4, élèves par salle) avec masquage des cases trop petites.
 */
final class PresenceAggregatesTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
    }

    public function testLesFournisseursCompteSansNom(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);
        $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY, 'director', ['presence' => 'present', 'arrived_at' => '07:25']);

        $pupils = $this->container()->get(PupilPresenceAggregates::class)->countsFor('t1', 'e1', self::DAY);
        $teachers = $this->container()->get(TeacherPresenceAggregates::class)->countsFor('t1', 'e1', self::DAY);

        self::assertSame(['reporting=school' => 1, 'state=absent' => 1, 'state=present' => 2], $pupils);
        self::assertSame(['presence=present' => 1], $teachers);
        self::assertSame([], $this->container()->get(PupilPresenceAggregates::class)->countsFor('t1', 'e1', '2026-10-09'), 'Sans appel, l\'école n\'est pas comptée présente.');
        self::assertSame([], $this->container()->get(PupilPresenceAggregates::class)->countsFor('t2', 'e1', self::DAY), 'Isolation entre clients.');
        self::assertSame([], $this->container()->get(TeacherPresenceAggregates::class)->countsFor('t1', 'e2', self::DAY), 'Isolation entre établissements.');
    }

    public function testLesIndicateursDerivesDePresenceEtDeSalles(): void
    {
        $derived = array_column(DashboardService::derive([
            self::indicator('public_schools.enrolment', ['level=CE1;sex=F' => 120, 'level=CE1;sex=M' => 100], 4),
            self::indicator('public_schools.classrooms_in_use', ['rooms=in_use' => 5], 4),
            self::indicator('public_schools.pupil_presence', ['reporting=school' => 3, 'state=absent' => 20, 'state=present' => 180], 4),
            self::indicator('public_schools.teacher_presence', ['presence=present' => 9, 'presence=absent' => 1], 4),
        ]), null, 'code');

        self::assertEquals(44.0, $derived['pupils_per_classroom']['value']);
        self::assertEquals(90.0, $derived['pupil_attendance_rate']['value']);
        self::assertEquals(90.0, $derived['teacher_attendance_rate']['value']);
        self::assertEquals(75.0, $derived['presence_reporting_rate']['value'], '3 écoles sur 4 ont remonté la présence.');
        self::assertFalse($derived['pupil_attendance_rate']['masked']);
    }

    public function testUneCaseMasqueeRendLIndicateurMasque(): void
    {
        $derived = array_column(DashboardService::derive([
            self::indicator('public_schools.pupil_presence', ['reporting=school' => 1, 'state=absent' => null, 'state=present' => 180], 4),
            self::indicator('public_schools.teacher_presence', ['presence=present' => null, 'presence=absent' => 1], 4),
        ]), null, 'code');

        self::assertTrue($derived['pupil_attendance_rate']['masked']);
        self::assertNull($derived['pupil_attendance_rate']['value']);
        self::assertTrue($derived['teacher_attendance_rate']['masked']);
        self::assertNull($derived['pupils_per_classroom']['value']);
        self::assertFalse($derived['pupils_per_classroom']['masked'], 'Aucune donnée : non calculable, pas masquée.');
    }

    /**
     * @param array<string, int|null> $cells
     * @return array{key: string, establishment_count: int, computed_at: string, cells: list<array{key: string, value: int|null, suppressed: bool}>}
     */
    private static function indicator(string $key, array $cells, int $schools): array
    {
        $list = [];
        foreach ($cells as $cell => $value) {
            $list[] = ['key' => $cell, 'value' => $value, 'suppressed' => $value === null];
        }

        return ['key' => $key, 'establishment_count' => $schools, 'computed_at' => '2026-10-08T03:00:00+01:00', 'cells' => $list];
    }
}
