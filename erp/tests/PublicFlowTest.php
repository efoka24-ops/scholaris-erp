<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;

/**
 * Parcours ouverts au public, sans compte :
 *  - la pre-inscription en ligne, deposee par un parent ;
 *  - la demande de creation d'etablissement, deposee par un chef
 *    d'etablissement, puis instruite par le Super Admin.
 *
 * Ces routes sont les seules accessibles sans authentification : leurs
 * garde-fous (etablissement ferme, code deja pris, reservation au Super Admin)
 * sont donc verifies ici un par un.
 */
final class PublicFlowTest extends TestCase
{
    // --- Pre-inscription en ligne --------------------------------------------

    public function testLeFormulaireNeProposeQueLesEtablissementsOuverts(): void
    {
        $this->openEnrollment($this->tenantA);

        $html = $this->request('GET', '/pre-inscription')->content();

        $this->assertStringContains('AAA', $html, 'L etablissement ouvert doit etre propose');
        $this->assertTrue(
            ! str_contains($html, '"BBB"'),
            'Un etablissement qui n a pas ouvert la pre-inscription ne doit pas apparaitre'
        );
    }

    public function testUnParentDeposeUneDemande(): void
    {
        $this->openEnrollment($this->tenantA);
        $this->createAcademicYear($this->tenantA);

        $response = $this->request('POST', '/pre-inscription', [
            'tenant_code' => 'AAA',
            'applicant_first_name' => 'Awa',
            'applicant_last_name' => 'NDONGO',
            'date_of_birth' => '2012-04-03',
            'gender' => 'FEMALE',
            'parent_name' => 'Oumarou NDONGO',
            'parent_phone' => '+237690112233',
            'level_wanted' => '6eme',
        ]);

        $this->assertSame(200, $response->status(), 'La confirmation doit s afficher');
        $this->assertStringContains('Reference du dossier', $response->content(), 'Une reference est remise au parent');

        $application = $this->db->selectOne('SELECT * FROM admission_applications LIMIT 1');

        $this->assertTrue($application !== null, 'La demande doit etre enregistree');
        $this->assertSame('PENDING', (string) $application['status'], 'Elle part en attente d instruction');
        $this->assertSame($this->tenantA, (string) $application['tenant_id'], 'Rattachee au bon etablissement');

        $info = json_decode((string) $application['applicant_info'], true);
        $this->assertSame('+237690112233', $info['parent']['phone'] ?? null, 'Le contact du parent est conserve');
    }

    public function testUneDemandeVersUnEtablissementFermeEstRefusee(): void
    {
        // L'etablissement B n'a pas ouvert la pre-inscription : son code ne doit
        // pas etre accepte, meme fourni directement.
        $this->createAcademicYear($this->tenantB);

        $this->request('POST', '/pre-inscription', [
            'tenant_code' => 'BBB',
            'applicant_first_name' => 'Test',
            'applicant_last_name' => 'TEST',
            'date_of_birth' => '2012-04-03',
            'gender' => 'MALE',
            'parent_name' => 'Parent',
            'parent_phone' => '690000000',
        ]);

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM admission_applications'),
            'Aucune demande ne doit etre enregistree'
        );
    }

    public function testUneDemandeIncompleteNEnregistreRien(): void
    {
        $this->openEnrollment($this->tenantA);
        $this->createAcademicYear($this->tenantA);

        $this->request('POST', '/pre-inscription', [
            'tenant_code' => 'AAA',
            'applicant_first_name' => 'Sans',
        ]);

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM admission_applications'),
            'Un formulaire incomplet ne doit rien creer'
        );
    }

    // --- Demande de creation d'etablissement ---------------------------------

    public function testUnChefDEtablissementDeposeUneDemande(): void
    {
        $response = $this->request('POST', '/demande-etablissement', [
            'name' => 'Lycee Bilingue de Garoua',
            'code' => 'lbg',
            'type' => 'LYCEE_GENERAL',
            'status' => 'PUBLIC',
            'director_first_name' => 'Amadou',
            'director_last_name' => 'BOUBA',
            'director_email' => 'proviseur@lbg.cm',
        ]);

        $this->assertSame(200, $response->status(), 'La confirmation doit s afficher');

        $demand = $this->db->selectOne('SELECT * FROM establishment_requests LIMIT 1');

        $this->assertTrue($demand !== null, 'La demande doit etre enregistree');
        $this->assertSame('LBG', (string) $demand['code'], 'Le code est normalise en majuscules');
        $this->assertSame('PENDING', (string) $demand['request_status'], 'Elle part en attente');
    }

    public function testUnCodeDejaUtiliseEstRefuse(): void
    {
        // "AAA" est deja le code de l'etablissement A : accepter la demande
        // conduirait a un conflit au moment de la creation.
        $this->request('POST', '/demande-etablissement', [
            'name' => 'Autre ecole',
            'code' => 'AAA',
            'type' => 'LYCEE_GENERAL',
            'status' => 'PRIVE',
            'director_first_name' => 'Test',
            'director_last_name' => 'TEST',
            'director_email' => 'test@example.cm',
        ]);

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM establishment_requests'),
            'Un code deja pris doit etre refuse des le depot'
        );
    }

    public function testLApprobationCreeEtablissementEtCompteResponsable(): void
    {
        $demandId = $this->createRequest('LYC', 'Lycee de Test', 'directeur@lyc.cm');

        $superAdmin = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($superAdmin, 'SUPER_ADMIN', []);
        $this->actingAs($superAdmin);

        $response = $this->request('POST', '/admin/etablissements/'.$demandId.'/approuver');

        $this->assertSame(200, $response->status(), 'Le recapitulatif doit s afficher');
        $this->assertStringContains('Mot de passe provisoire', $response->content(), 'Le mot de passe est affiche une fois');

        $tenant = $this->db->selectOne('SELECT * FROM tenants WHERE code = :code', ['code' => 'LYC']);
        $this->assertTrue($tenant !== null, 'L etablissement doit etre cree');

        $user = $this->db->selectOne('SELECT * FROM users WHERE email = :email', ['email' => 'directeur@lyc.cm']);
        $this->assertTrue($user !== null, 'Le compte du responsable doit etre cree');
        $this->assertSame((string) $tenant['id'], (string) $user['tenant_id'], 'Rattache au nouvel etablissement');

        // La structure pedagogique par defaut evite a un nouvel etablissement de
        // partir d'une base vide.
        $levels = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM levels WHERE tenant_id = :tenant',
            ['tenant' => $tenant['id']]
        );
        $this->assertSame(7, $levels, 'Les niveaux du college et du lycee doivent etre poses');

        $demand = $this->db->selectOne('SELECT * FROM establishment_requests WHERE id = :id', ['id' => $demandId]);
        $this->assertSame('APPROVED', (string) $demand['request_status'], 'La demande passe a approuvee');
    }

    public function testUneDemandeNePeutPasEtreApprouveeDeuxFois(): void
    {
        $demandId = $this->createRequest('DBL', 'Ecole doublon', 'chef@dbl.cm');

        $superAdmin = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($superAdmin, 'SUPER_ADMIN', []);
        $this->actingAs($superAdmin);

        $this->request('POST', '/admin/etablissements/'.$demandId.'/approuver');
        $this->request('POST', '/admin/etablissements/'.$demandId.'/approuver');

        $tenants = (int) $this->db->scalar('SELECT COUNT(*) FROM tenants WHERE code = :code', ['code' => 'DBL']);

        $this->assertSame(1, $tenants, 'Une seconde approbation ne doit pas recreer l etablissement');
    }

    public function testSeulLeSuperAdminInstruitLesDemandes(): void
    {
        $demandId = $this->createRequest('REF', 'Ecole refusee', 'chef@ref.cm');

        // Un utilisateur portant pourtant tenants:create ne doit pas pouvoir
        // approuver : ces demandes ne sont rattachees a aucun etablissement, la
        // permission seule ne suffit donc pas.
        $user = $this->createUser($this->tenantA, 'admin@a.cm');
        $this->giveRole($user, 'ADMIN_LOCAL', ['tenants:create', 'tenants:read', 'tenants:update']);
        $this->actingAs($user);

        $response = $this->request('POST', '/admin/etablissements/'.$demandId.'/approuver');

        $this->assertSame(403, $response->status(), 'L acces doit etre refuse');
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM tenants WHERE code = :code', ['code' => 'REF']),
            'Aucun etablissement ne doit avoir ete cree'
        );
    }

    public function testLeRefusExigeUnMotif(): void
    {
        $demandId = $this->createRequest('MOT', 'Ecole sans motif', 'chef@mot.cm');

        $superAdmin = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($superAdmin, 'SUPER_ADMIN', []);
        $this->actingAs($superAdmin);

        $this->request('POST', '/admin/etablissements/'.$demandId.'/refuser', ['reason' => '']);

        $demand = $this->db->selectOne('SELECT request_status FROM establishment_requests WHERE id = :id', ['id' => $demandId]);
        $this->assertSame('PENDING', (string) $demand['request_status'], 'Sans motif, la demande reste en attente');

        $this->request('POST', '/admin/etablissements/'.$demandId.'/refuser', ['reason' => 'Dossier incomplet']);

        $demand = $this->db->selectOne('SELECT * FROM establishment_requests WHERE id = :id', ['id' => $demandId]);
        $this->assertSame('REJECTED', (string) $demand['request_status'], 'Avec motif, la demande est refusee');
        $this->assertSame('Dossier incomplet', (string) $demand['rejection_reason'], 'Le motif est conserve');
    }

    // --- Outillage -----------------------------------------------------------

    private function openEnrollment(string $tenantId): void
    {
        $this->db->execute(
            'UPDATE tenants SET public_enrollment_enabled = 1 WHERE id = :id',
            ['id' => $tenantId]
        );
    }

    private function createAcademicYear(string $tenantId): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'label' => '2026-2027',
                'start' => '2026-09-01',
                'end' => '2027-06-30',
                'status' => 'ACTIVE',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    public function testUneDemandeDEcolePrimaireNOuvrePasSurLaTerminale(): void
    {
        // Le type demande commande la structure posee. Une ecole primaire
        // validee avec des classes de lycee serait inexploitable, et son
        // directeur y verrait des niveaux qu'il n'ouvrira jamais.
        $demandId = $this->createRequest('EPT', 'Ecole primaire de Test', 'directeur@ept.cm', 'PRIMAIRE');

        $superAdmin = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($superAdmin, 'SUPER_ADMIN', []);
        $this->actingAs($superAdmin);

        $this->request('POST', '/admin/etablissements/'.$demandId.'/approuver');

        $tenantId = $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPT']);

        $codes = array_column($this->db->select(
            'SELECT code FROM levels WHERE tenant_id = :tenant ORDER BY sort_order',
            ['tenant' => $tenantId]
        ), 'code');

        $this->assertSame(['SIL', 'CP', 'CE1', 'CE2', 'CM1', 'CM2'], $codes, 'Le primaire va de la SIL au CM2');
    }

    private function createRequest(string $code, string $name, string $email, string $type = 'LYCEE_GENERAL'): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO establishment_requests
                (id, name, code, type, status, director_first_name, director_last_name,
                 director_email, request_status, created_at, updated_at)
             VALUES (:id, :name, :code, :type, :status, :first, :last, :email, :rstatus, :created_at, :updated_at)',
            [
                'id' => $id,
                'name' => $name,
                'code' => $code,
                'type' => $type,
                'status' => 'PUBLIC',
                'first' => 'Chef',
                'last' => 'ETABLISSEMENT',
                'email' => $email,
                'rstatus' => 'PENDING',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }
}
