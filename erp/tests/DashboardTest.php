<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;

/**
 * Le tableau de bord de chacun.
 *
 * Servir les memes chiffres a tout le monde obligerait chaque profil a chercher
 * ce qui le concerne au milieu de ce qui ne le concerne pas. Ces tests fixent
 * qui voit quoi, et surtout qui ne voit pas quoi : un parent ne doit pas
 * apercevoir les enfants des autres.
 */
final class DashboardTest extends TestCase
{
    public function testLaDirectionVoitLaVueDEnsemble(): void
    {
        $userId = $this->createUser($this->tenantA, 'directeur@a.cm');
        // Profil du Directeur : consulte la finance, publie les notes, mais
        // n'encaisse pas.
        $this->giveRole($userId, 'DIRECTEUR', [
            'students:read', 'finance-dashboard:read', 'grades:publish', 'grades:read',
        ]);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('Tableau de bord', $content, 'La direction obtient la vue d ensemble');
        $this->assertTrue(
            ! str_contains($content, 'Soldes les plus eleves'),
            'Et non le tableau de recouvrement de l intendance'
        );
    }

    public function testLIntendanceVoitLeRecouvrement(): void
    {
        $userId = $this->createUser($this->tenantA, 'intendant@a.cm');
        // Encaisser est le propre de l'intendance.
        $this->giveRole($userId, 'INTENDANT', [
            'finance-dashboard:read', 'payments:create', 'invoices:read', 'students:read',
        ]);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('Intendance', $content, 'L intendance obtient son tableau');
        $this->assertStringContains('Taux de recouvrement', $content, 'Avec le taux de recouvrement');
    }

    public function testUnEnseignantVoitSesClassesEtSaSaisie(): void
    {
        $userId = $this->createUser($this->tenantA, 'prof@a.cm');
        $this->giveRole($userId, 'ENSEIGNANT', ['grades:create', 'grades:read', 'students:read']);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('Mon tableau de bord', $content, 'L enseignant a son propre tableau');
        $this->assertStringContains('Ma saisie', $content, 'Centre sur l avancement de sa saisie');
    }

    public function testUnParentNeVoitQueSesEnfants(): void
    {
        $mine = $this->createStudent($this->tenantA, 'A/001', 'MONENFANT');
        $other = $this->createStudent($this->tenantA, 'A/002', 'AUTREENFANT');

        $userId = $this->createUser($this->tenantA, 'parent@a.cm');
        $this->giveRole($userId, 'PARENT', ['students:read']);
        $this->linkParent($userId, $mine);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('Mes enfants', $content, 'Le parent a son propre tableau');
        $this->assertStringContains('MONENFANT', $content, 'Son enfant y figure');
        $this->assertTrue(
            ! str_contains($content, 'AUTREENFANT'),
            "L'enfant d'une autre famille ne doit jamais apparaitre"
        );
    }

    public function testUnEleveNeVoitQueLuiMeme(): void
    {
        $studentId = $this->createStudent($this->tenantA, 'A/010', 'MOI');
        $this->createStudent($this->tenantA, 'A/011', 'CAMARADE');

        $userId = $this->createUser($this->tenantA, 'eleve@a.cm');
        $this->giveRole($userId, 'ELEVE', ['students:read']);

        $this->db->execute('UPDATE students SET user_id = :user WHERE id = :id', ['user' => $userId, 'id' => $studentId]);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('A/010', $content, 'Son matricule apparait');
        $this->assertTrue(! str_contains($content, 'CAMARADE'), 'Pas ceux de ses camarades');
    }

    public function testUnParentNeVoitPasUnResultatNonPublie(): void
    {
        $studentId = $this->createStudent($this->tenantA, 'A/020', 'ENFANT');
        $userId = $this->createUser($this->tenantA, 'parent@a.cm');
        $this->giveRole($userId, 'PARENT', ['students:read']);
        $this->linkParent($userId, $studentId);

        // Resultat calcule mais non publie : le conseil de classe ne l'a pas
        // encore arrete, la famille ne doit pas le decouvrir avant.
        $this->createPeriodResult($studentId, 14.5, false);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('Aucun résultat publié', $content, 'Rien ne doit filtrer avant publication');
        $this->assertTrue(! str_contains($content, '14,50'), 'La moyenne ne doit pas apparaitre');
    }

    public function testUnResultatPublieDevientVisibleDuParent(): void
    {
        $studentId = $this->createStudent($this->tenantA, 'A/021', 'ENFANT');
        $userId = $this->createUser($this->tenantA, 'parent@a.cm');
        $this->giveRole($userId, 'PARENT', ['students:read']);
        $this->linkParent($userId, $studentId);

        $this->createPeriodResult($studentId, 14.5, true);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('14,50', $content, 'Une fois publiee, la moyenne est visible');
    }

    // --- Outillage -----------------------------------------------------------

    private function linkParent(string $userId, string $studentId): void
    {
        $parentId = Table::uuid();

        $this->db->execute(
            'INSERT INTO parents (id, tenant_id, first_name, last_name, phone, relationship, user_id, created_at, updated_at)
             VALUES (:id, :tenant, :first, :last, :phone, :rel, :user, :created_at, :updated_at)',
            [
                'id' => $parentId,
                'tenant' => $this->tenantA,
                'first' => 'Parent',
                'last' => 'TEST',
                'phone' => '+237600000000',
                'rel' => 'FATHER',
                'user' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        $this->db->execute(
            'INSERT INTO student_parents (student_id, parent_id, relationship) VALUES (:student, :parent, :rel)',
            ['student' => $studentId, 'parent' => $parentId, 'rel' => 'FATHER']
        );
    }

    private function createPeriodResult(string $studentId, float $average, bool $published): void
    {
        $now = date('Y-m-d H:i:s');
        $yearId = Table::uuid();

        $this->db->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $yearId, 'tenant' => $this->tenantA, 'label' => '2026-2027',
                'start' => '2026-09-01', 'end' => '2027-06-30', 'status' => 'ACTIVE', 'created_at' => $now,
            ]
        );

        $periodId = Table::uuid();

        $this->db->execute(
            'INSERT INTO periods (id, academic_year_id, type, number, start_date, end_date, grading_status)
             VALUES (:id, :year, :type, :number, :start, :end, :status)',
            [
                'id' => $periodId, 'year' => $yearId, 'type' => 'SEQUENCE', 'number' => 1,
                'start' => '2026-09-01', 'end' => '2026-10-15', 'status' => 'OPEN',
            ]
        );

        $cycleId = Table::uuid();
        $this->db->execute(
            'INSERT INTO cycles (id, tenant_id, code, name, sort_order, created_at)
             VALUES (:id, :tenant, :code, :name, :sort, :created_at)',
            ['id' => $cycleId, 'tenant' => $this->tenantA, 'code' => 'C', 'name' => 'Cycle', 'sort' => 1, 'created_at' => $now]
        );

        $levelId = Table::uuid();
        $this->db->execute(
            'INSERT INTO levels (id, tenant_id, code, name, sort_order, cycle_id, created_at)
             VALUES (:id, :tenant, :code, :name, :sort, :cycle, :created_at)',
            ['id' => $levelId, 'tenant' => $this->tenantA, 'code' => 'L', 'name' => 'Niveau', 'sort' => 1, 'cycle' => $cycleId, 'created_at' => $now]
        );

        $classroomId = Table::uuid();
        $this->db->execute(
            'INSERT INTO classrooms (id, tenant_id, code, name, capacity, level_id, section, created_at, updated_at)
             VALUES (:id, :tenant, :code, :name, :capacity, :level, :section, :created_at, :updated_at)',
            [
                'id' => $classroomId, 'tenant' => $this->tenantA, 'code' => 'CL', 'name' => 'Classe',
                'capacity' => 30, 'level' => $levelId, 'section' => 'FRANCOPHONE',
                'created_at' => $now, 'updated_at' => $now,
            ]
        );

        $this->db->execute(
            'INSERT INTO period_results (id, tenant_id, student_id, period_id, classroom_id, general_average,
                 rank_position, total_students, mention, is_published, created_at, updated_at)
             VALUES (:id, :tenant, :student, :period, :classroom, :average, :rank, :total, :mention, :published, :created_at, :updated_at)',
            [
                'id' => Table::uuid(), 'tenant' => $this->tenantA, 'student' => $studentId,
                'period' => $periodId, 'classroom' => $classroomId, 'average' => $average,
                'rank' => 1, 'total' => 30, 'mention' => 'Bien',
                'published' => $published ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
            ]
        );
    }
}
