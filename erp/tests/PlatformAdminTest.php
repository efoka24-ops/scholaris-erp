<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;

/**
 * Separation entre l'administrateur de la plateforme et celui d'une ecole.
 *
 * Ce sont deux roles distincts, souvent confondus : le Super Admin gere le parc
 * d'etablissements et n'appartient a aucun d'eux ; l'administrateur d'une ecole
 * (souvent le directeur) gere son etablissement et ne voit que le sien.
 */
final class PlatformAdminTest extends TestCase
{
    public function testUnAdministrateurDePlateformeNAppartientAAucuneEcole(): void
    {
        $id = $this->createPlatformAdmin('super@plateforme.cm');

        $row = $this->db->selectOne('SELECT tenant_id FROM users WHERE id = :id', ['id' => $id]);

        $this->assertNull($row['tenant_id'], 'Son tenant_id doit etre nul');
    }

    public function testIlSeConnecteSansEtablissementCourant(): void
    {
        $this->createPlatformAdmin('super@plateforme.cm');

        $this->request('POST', '/login', [
            'email' => 'super@plateforme.cm',
            'password' => 'Test123!',
        ]);

        $this->assertTrue($this->app->auth()->check(), 'La connexion doit aboutir');
        $this->assertTrue($this->app->auth()->isPlatformAccount(), 'Le compte est reconnu comme compte de plateforme');
        $this->assertTrue(
            ! $this->app->tenant()->isSet(),
            'Aucun etablissement courant ne doit etre pose a la connexion'
        );
    }

    public function testSonTableauDeBordEstCeluiDeLaPlateforme(): void
    {
        $id = $this->createPlatformAdmin('super@plateforme.cm');
        $this->actingAs($id);

        // Sans etablissement, le tableau de bord scolaire n'a aucun sens : il
        // renvoie vers l'espace plateforme.
        $response = $this->request('GET', '/dashboard');

        $this->assertSame(302, $response->status(), 'Le tableau de bord scolaire doit rediriger');
        $this->assertSame('/admin', $response->header('Location'), 'Vers l espace plateforme');

        $platform = $this->request('GET', '/admin');
        $this->assertSame(200, $platform->status(), 'L espace plateforme doit s afficher');
        $this->assertStringContains('Etablissement A', $platform->content(), 'Les etablissements sont listes');
        $this->assertStringContains('Etablissement B', $platform->content(), 'Tous, pas seulement un');
    }

    public function testIlDoitEntrerDansUneEcolePourEnVoirLesDonnees(): void
    {
        $this->createStudent($this->tenantA, 'A/001');
        $id = $this->createPlatformAdmin('super@plateforme.cm');
        $this->actingAs($id);

        // Sans etablissement courant, une lecture scopee echoue : le defaut est
        // le refus, meme pour le Super Admin.
        $this->assertThrows(
            fn () => $this->app->table('students')->get(),
            'Une lecture scopee sans etablissement doit echouer'
        );

        $this->request('POST', '/admin/etablissements/'.$this->tenantA.'/consulter');

        $this->assertSame(
            $this->tenantA,
            $this->app->tenant()->id(),
            'Apres etre entre, l etablissement courant est pose'
        );
        $this->assertCount(1, $this->app->table('students')->get(), 'Les donnees de cette ecole sont lisibles');
    }

    public function testEntrerDansUneEcoleEstJournalise(): void
    {
        $id = $this->createPlatformAdmin('super@plateforme.cm');
        $this->actingAs($id);

        $this->request('POST', '/admin/etablissements/'.$this->tenantA.'/consulter');

        $log = $this->db->selectOne(
            'SELECT * FROM audit_logs WHERE action = :action',
            ['action' => 'platform.enter_tenant']
        );

        $this->assertTrue($log !== null, 'L acces doit laisser une trace');
        $this->assertSame($this->tenantA, (string) $log['resource_id'], 'La trace nomme l etablissement consulte');
        $this->assertSame($id, (string) $log['user_id'], 'Et l administrateur concerne');
    }

    public function testIlPeutQuitterLEtablissementConsulte(): void
    {
        $id = $this->createPlatformAdmin('super@plateforme.cm');
        $this->actingAs($id);

        $this->request('POST', '/admin/etablissements/'.$this->tenantA.'/consulter');
        $this->assertTrue($this->app->tenant()->isSet(), 'Il consulte bien un etablissement');

        $this->request('POST', '/admin/quitter');

        $this->assertTrue(
            ! $this->app->tenant()->isSet(),
            'Apres avoir quitte, plus aucun etablissement courant'
        );
    }

    public function testUnAdministrateurDEcoleNAccedePasALaPlateforme(): void
    {
        // Cas typique du directeur : il administre son etablissement, avec des
        // droits etendus, mais reste enferme dedans.
        $directeur = $this->createUser($this->tenantA, 'directeur@a.cm');
        $this->giveRole($directeur, 'Admin Établissement', [
            'tenants:read', 'tenants:update', 'students:manage', 'users:manage',
        ]);
        $this->actingAs($directeur);

        $response = $this->request('GET', '/admin');

        $this->assertSame(403, $response->status(), 'L espace plateforme doit lui etre refuse');
    }

    public function testUnAdministrateurDEcoleNeVoitQueSonEtablissement(): void
    {
        $this->createStudent($this->tenantA, 'A/001');
        $this->createStudent($this->tenantB, 'B/001');

        $directeur = $this->createUser($this->tenantA, 'directeur@a.cm');
        $this->giveRole($directeur, 'Admin Établissement', ['students:read']);
        $this->actingAs($directeur);

        $this->assertCount(
            1,
            $this->app->table('students')->get(),
            'Seuls les eleves de son etablissement remontent'
        );

        $response = $this->request('POST', '/admin/etablissements/'.$this->tenantB.'/consulter');
        $this->assertSame(403, $response->status(), 'Il ne peut pas se placer dans une autre ecole');
    }

    private function createPlatformAdmin(string $email): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status, created_at, updated_at)
             VALUES (:id, NULL, :email, :hash, :first, :last, :status, :created_at, :updated_at)',
            [
                'id' => $id,
                'email' => $email,
                'hash' => \Scholaris\Auth\Auth::hash('Test123!'),
                'first' => 'Super',
                'last' => 'Admin',
                'status' => 'ACTIVE',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        $this->giveRole($id, 'SUPER_ADMIN', ['tenants:read', 'tenants:create', 'tenants:update']);

        return $id;
    }
}
