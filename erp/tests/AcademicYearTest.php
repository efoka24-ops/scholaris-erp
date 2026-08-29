<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;

/**
 * Annee scolaire et periodes de saisie.
 *
 * Deux regles portent tout le reste : une seule annee active, une seule
 * sequence ouverte. Les enfreindre ne provoque aucune erreur visible — les
 * notes se rattachent simplement a la mauvaise periode, et personne ne s en
 * apercoit avant les bulletins.
 */
final class AcademicYearTest extends TestCase
{
    private function actAsAdmin(): string
    {
        $userId = $this->createUser($this->tenantA, 'admin@a.cm');
        $this->giveRole($userId, 'ADMIN_ETAB', [
            'academic-years:read', 'academic-years:create', 'academic-years:update',
        ]);
        $this->actingAs($userId);

        return $userId;
    }

    public function testLOuvertureDUneAnneeCreeSesSequences(): void
    {
        $this->actAsAdmin();

        $this->request('POST', '/annees-scolaires', ['start_year' => '2026']);

        $year = $this->db->selectOne(
            'SELECT * FROM academic_years WHERE tenant_id = :tenant',
            ['tenant' => $this->tenantA]
        );

        $this->assertTrue($year !== null, 'L annee doit etre creee');
        $this->assertSame('2026-2027', (string) $year['label'], 'Libellee sur deux annees civiles');
        $this->assertSame('ACTIVE', (string) $year['status'], 'Et active immediatement');

        $periods = $this->db->select(
            'SELECT * FROM periods WHERE academic_year_id = :year ORDER BY number',
            ['year' => $year['id']]
        );

        $this->assertSame(6, count($periods), 'Six sequences, decoupage standard');
        $this->assertSame('OPEN', (string) $periods[0]['grading_status'], 'La premiere est ouverte a la saisie');
        $this->assertSame('CLOSED', (string) $periods[1]['grading_status'], 'Les suivantes attendent leur tour');
    }

    public function testUneSeuleAnneeResteActive(): void
    {
        // Deux annees actives rendraient ambigu le rattachement de toute
        // inscription : dans laquelle l'eleve est-il inscrit ?
        $this->actAsAdmin();

        $this->request('POST', '/annees-scolaires', ['start_year' => '2026']);
        $this->request('POST', '/annees-scolaires', ['start_year' => '2027']);

        $active = $this->db->select(
            "SELECT label FROM academic_years WHERE tenant_id = :tenant AND status = 'ACTIVE'",
            ['tenant' => $this->tenantA]
        );

        $this->assertSame(1, count($active), 'Une seule annee active a la fois');
        $this->assertSame('2027-2028', (string) $active[0]['label'], 'La derniere ouverte prend la main');
    }

    public function testUneSeuleSequenceResteOuverteALaSaisie(): void
    {
        // Sinon un enseignant saisit dans la sequence precedente sans s'en
        // apercevoir, et la note compte pour la mauvaise periode.
        $this->actAsAdmin();
        $this->request('POST', '/annees-scolaires', ['start_year' => '2026']);

        $periods = $this->db->select(
            'SELECT p.id FROM periods p JOIN academic_years y ON y.id = p.academic_year_id
             WHERE y.tenant_id = :tenant ORDER BY p.number',
            ['tenant' => $this->tenantA]
        );

        $this->request('POST', '/periodes/'.$periods[2]['id'].'/ouvrir');

        $open = $this->db->select(
            "SELECT p.number FROM periods p JOIN academic_years y ON y.id = p.academic_year_id
             WHERE y.tenant_id = :tenant AND p.grading_status = 'OPEN'",
            ['tenant' => $this->tenantA]
        );

        $this->assertSame(1, count($open), 'Une seule sequence ouverte');
        $this->assertSame(3, (int) $open[0]['number'], 'Celle qui vient d etre ouverte');
    }

    public function testUnAdminNePeutPasOuvrirLaSequenceDUneAutreEcole(): void
    {
        // La table periods ne porte pas de tenant_id : sans jointure sur
        // l'annee, l'identifiant d'une periode etrangere suffirait a la
        // manipuler depuis un autre etablissement.
        $foreignPeriod = $this->createPeriodFor($this->tenantB);

        $this->actAsAdmin();
        $this->request('POST', '/periodes/'.$foreignPeriod.'/ouvrir');

        $status = $this->db->scalar(
            'SELECT grading_status FROM periods WHERE id = :id',
            ['id' => $foreignPeriod]
        );

        $this->assertSame('CLOSED', (string) $status, 'La periode de l autre ecole ne bouge pas');
    }

    public function testUneAnneeDejaOuverteNEstPasCreeeDeuxFois(): void
    {
        $this->actAsAdmin();

        $this->request('POST', '/annees-scolaires', ['start_year' => '2026']);
        $this->request('POST', '/annees-scolaires', ['start_year' => '2026']);

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM academic_years WHERE tenant_id = :tenant',
            ['tenant' => $this->tenantA]
        );

        $this->assertSame(1, $count, 'Un doublon d annee doit etre refuse');
    }

    public function testUneAnneeDeDebutAberranteEstRefusee(): void
    {
        $this->actAsAdmin();

        $this->request('POST', '/annees-scolaires', ['start_year' => '12']);

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM academic_years WHERE tenant_id = :tenant', ['tenant' => $this->tenantA]),
            'Une annee aberrante ne doit rien creer'
        );
    }

    public function testLEcranPrevientQuandAucuneAnneeNEstOuverte(): void
    {
        // C'est le premier ecran qu'ouvre un directeur d'un etablissement
        // neuf. Le laisser devant un tableau vide, sans explication, c'est le
        // laisser bloque.
        $this->actAsAdmin();

        $content = $this->request('GET', '/annees-scolaires')->content();

        $this->assertStringContains(
            "Aucune année scolaire n'est encore ouverte",
            $content,
            'L ecran doit dire quoi faire'
        );
    }

    private function createPeriodFor(string $tenantId): string
    {
        $yearId = Table::uuid();
        $periodId = Table::uuid();

        $this->db->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $yearId, 'tenant' => $tenantId, 'label' => '2026-2027',
                'start' => '2026-09-01', 'end' => '2027-06-30', 'status' => 'ACTIVE',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        $this->db->execute(
            'INSERT INTO periods (id, academic_year_id, type, number, start_date, end_date, grading_status)
             VALUES (:id, :year, :type, :number, :start, :end, :status)',
            [
                'id' => $periodId, 'year' => $yearId, 'type' => 'SEQUENCE', 'number' => 1,
                'start' => '2026-09-01', 'end' => '2026-10-15', 'status' => 'CLOSED',
            ]
        );

        return $periodId;
    }
}
