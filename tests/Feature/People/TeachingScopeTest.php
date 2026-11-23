<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Application\PeopleClock;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Infrastructure\StaffTeachingScope;
use Scholaris\Modules\Socle\Scheduling\Contracts\TeacherClasses;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;
use Tests\Support\People\PeopleTestCase;

/** Périmètre réel d'un enseignant : classes de ses affectations (Staff) et de son emploi du temps (Scheduling). */
final class TeachingScopeTest extends PeopleTestCase
{
    protected function setUp(): void
    {
        $this->bootPeople();
        $this->enrol('kid-c1', 'c1');
        $this->enrol('kid-c2', 'c2');
        $this->enrol('kid-c3', 'c3');
        $this->enrol('kid-c1-draft', 'c1', 'draft');
        $this->enrol('kid-other-tenant', 'c1', 'active', 't2');

        $this->assign('t1', 'tea-assigned', 'c1');
        $this->slot('t1', 'tea-timetable', 'c2', '2026-09-01', null);
        $this->slot('t1', 'tea-expired', 'c3', '2025-09-01', '2026-06-30');
        $this->assign('t1', 'tea-both', 'c1');
        $this->slot('t1', 'tea-both', 'c2', '2026-09-01', '2027-06-30');
    }

    private function enrol(string $learner, string $structure, string $status = 'active', string $tenant = 't1'): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)',
        )->execute([(string) new Ulid(), $tenant, $learner, 'e1', $structure, 'y2026', $status, '2026-09-01T08:00:00+01:00', '2026-09-01T08:00:00+01:00']);
    }

    private function assign(string $tenant, string $teacher, string $structure): void
    {
        $pdo = $this->connections()->pdo();
        $staffId = (string) new Ulid();
        $pdo->prepare('INSERT INTO staff_members (id, tenant_id, person_id, home_establishment_id, kind, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$staffId, $tenant, $teacher, 'e1', 'permanent', 'active', '2026-09-01T08:00:00+01:00', '2026-09-01T08:00:00+01:00']);
        $pdo->prepare('INSERT INTO staff_assignments (id, tenant_id, establishment_id, staff_id, structure_id, session_kind, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), $tenant, 'e1', $staffId, $structure, 'cm', 'active', '2026-09-01T08:00:00+01:00', '2026-09-01T08:00:00+01:00']);
    }

    private function slot(string $tenant, string $teacher, string $structure, string $from, ?string $to): void
    {
        $pdo = $this->connections()->pdo();
        $slotId = (string) new Ulid();
        $pdo->prepare('INSERT INTO timetable_slots (id, tenant_id, establishment_id, subject_label, teacher_person_id, weekday, start_time, end_time, valid_from, valid_to, period_of_day, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$slotId, $tenant, 'e1', 'Maths', $teacher, '08:00', '10:00', $from, $to, 'morning', '2026-09-01T08:00:00+01:00', '2026-09-01T08:00:00+01:00']);
        $pdo->prepare('INSERT INTO timetable_slot_structures (id, tenant_id, establishment_id, slot_id, structure_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), $tenant, 'e1', $slotId, $structure]);
    }

    private function scope(): StaffTeachingScope
    {
        $c = $this->kernel->container();

        return new StaffTeachingScope($c->get(TeacherWorkload::class), $c->get(TeacherClasses::class), $c->get(EnrollmentDirectory::class), $c->get(PeopleClock::class));
    }

    /** @return list<string> */
    private function learners(string $tenant, string $teacher): array
    {
        $ids = $this->scope()->learnerIdsOf($tenant, $teacher);
        sort($ids);

        return $ids;
    }

    public function testLesClassesViennentDesAffectationsEtDeLEmploiDuTemps(): void
    {
        self::assertSame(['kid-c1'], $this->learners('t1', 'tea-assigned'));
        self::assertSame(['kid-c2'], $this->learners('t1', 'tea-timetable'));
        self::assertSame(['kid-c1', 'kid-c2'], $this->learners('t1', 'tea-both'));
    }

    public function testUnCreneauExpireNeDonneAucunApprenant(): void
    {
        self::assertSame([], $this->learners('t1', 'tea-expired'));
    }

    public function testRefusParDefautEtIsolationEntreClients(): void
    {
        self::assertSame([], $this->learners('t1', 'inconnu'));
        self::assertSame([], $this->learners('t2', 'tea-assigned'), 'Un autre client n\'a pas cet enseignant.');
        self::assertNotContains('kid-other-tenant', $this->learners('t1', 'tea-assigned'));
    }

    public function testLaLiaisonDuConteneurEstLaVraieImplementation(): void
    {
        $real = \Scholaris\Core\Container\ContainerFactory::create(
            dirname(__DIR__, 3),
            \Scholaris\Core\Config\Config::fromArray(['app' => ['env' => 'testing', 'timezone' => 'Africa/Douala'], 'database' => ['driver' => 'sqlite', 'database' => ':memory:']]),
        );

        self::assertInstanceOf(StaffTeachingScope::class, $real->get(\Scholaris\Modules\Socle\People\Contracts\TeachingScope::class));
    }
}
