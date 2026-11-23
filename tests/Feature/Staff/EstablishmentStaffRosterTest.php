<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMemberInfo;
use Tests\Support\Staff\StaffTestCase;

/** Contrat EstablishmentStaffRoster : tout le personnel actif d'un établissement, enseignant ou non, cloisonné par client et établissement. */
final class EstablishmentStaffRosterTest extends StaffTestCase
{
    protected function setUp(): void
    {
        $this->bootStaff();
    }

    public function testListeToutLePersonnelActifAvecFonctionEtStatutSansFuite(): void
    {
        $this->member('m-prof', 't1', 'p-prof', 'e1', 'permanent', 'active', 'Professeur certifié', 'certifie');
        $this->member('m-sec', 't1', 'p-sec', 'e1', 'permanent', 'active', 'Secrétaire', 'administratif');
        $this->member('m-agent', 't1', 'p-agent', 'e1', 'vacataire', 'active', 'Agent d’entretien', 'entretien');
        $this->member('m-parti', 't1', 'p-parti', 'e1', 'permanent', 'left', 'Gardien', 'service');
        $this->member('m-e2', 't1', 'p-e2', 'e2', 'permanent', 'active', 'Comptable', 'administratif');
        $this->member('m-vac', 't1', 'p-vac', 'e2', 'vacataire', 'active', 'Professeur vacataire', null);
        $this->member('m-autre', 't2', 'p-autre', 'x1', 'permanent', 'active', 'Secrétaire', 'administratif');
        $this->assignment('a-1', 't1', 'e1', 'm-prof', 'Mathématiques');
        $this->assignment('a-2', 't1', 'e1', 'm-vac', 'Physique');
        $this->assignment('a-3', 't1', 'e2', 'm-sec', null);

        $roster = $this->kernel->container()->get(EstablishmentStaffRoster::class);
        $e1 = $roster->membersOf('t1', 'e1');
        self::assertSame(['p-agent', 'p-prof', 'p-sec', 'p-vac'], array_map(static fn (StaffMemberInfo $m): string => $m->personId, $e1), 'Rattachés ou affectés, actifs seulement.');
        $byPerson = [];
        foreach ($e1 as $member) {
            $byPerson[$member->personId] = $member;
        }
        self::assertSame(['m-sec', 'permanent', 'Secrétaire', 'administratif', false], [$byPerson['p-sec']->staffId, $byPerson['p-sec']->kind, $byPerson['p-sec']->grade, $byPerson['p-sec']->category, $byPerson['p-sec']->teaches]);
        self::assertTrue($byPerson['p-prof']->teaches);
        self::assertTrue($byPerson['p-vac']->teaches, 'Affecté pour enseigner dans e1.');
        self::assertFalse($byPerson['p-agent']->teaches);

        $e2 = array_map(static fn (StaffMemberInfo $m): string => $m->personId, $roster->membersOf('t1', 'e2'));
        self::assertSame(['p-e2', 'p-sec', 'p-vac'], $e2, 'Autre établissement : ses membres seulement.');
        self::assertSame([], $roster->membersOf('t2', 'e1'), 'Autre client : rien.');
        self::assertSame(['p-autre'], array_map(static fn (StaffMemberInfo $m): string => $m->personId, $roster->membersOf('t2', 'x1')));
    }

    private function member(string $id, string $tenant, string $person, string $home, string $kind, string $status, ?string $grade, ?string $category): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO staff_members (id, tenant_id, person_id, home_establishment_id, kind, status, grade, category, employee_number, department_id, hired_on, left_on, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?)',
        )->execute([$id, $tenant, $person, $home, $kind, $status, $grade, $category, '2026-10-01T08:00:00+01:00', '2026-10-01T08:00:00+01:00']);
    }

    private function assignment(string $id, string $tenant, string $establishment, string $staffId, ?string $subject): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO staff_assignments (id, tenant_id, establishment_id, staff_id, academic_year_id, structure_id, subject_id, subject_label, session_kind, planned_minutes, starts_on, ends_on, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?, ?, 0, NULL, NULL, ?, NULL, ?, ?)',
        )->execute([$id, $tenant, $establishment, $staffId, $subject, 'td', 'active', '2026-10-01T08:00:00+01:00', '2026-10-01T08:00:00+01:00']);
    }
}
