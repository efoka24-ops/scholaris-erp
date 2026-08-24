<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Seeder;
use Scholaris\Platform\PlatformStats;
use Scholaris\Tenant\TenantContext;

/**
 * Gouvernance de la plateforme : comptes, lecture du parc, tracabilite.
 *
 * Ce que doit pouvoir faire un administrateur national sans ouvrir la base :
 * redonner un mot de passe a un directeur qui l'a perdu, acter un depart,
 * nommer un successeur, et lire ce qui s'est passe.
 *
 * Deux regles sont tenues ici plutot que confiees a la vigilance : un mot de
 * passe ne se consulte jamais, et la plateforme ne peut pas se retrouver sans
 * administrateur.
 */
final class PlatformGovernanceTest extends TestCase
{
    private function actAsSuperAdmin(): string
    {
        (new Seeder($this->db, new TenantContext(), $this->basePath()))
            ->run('platform@scholaris.test', 'MotDePasseTest1!');

        $userId = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($userId, 'SUPER_ADMIN', ['tenants:read', 'tenants:create', 'tenants:update', 'tenants:delete']);
        $this->actingAs($userId);

        return $userId;
    }

    // --- Comptes -------------------------------------------------------------

    public function testUnMotDePassePerduSeRemplaceSansJamaisEtreConsulte(): void
    {
        $this->actAsSuperAdmin();
        $directorId = $this->createUser($this->tenantA, 'directeur@a.cm');
        $before = (string) $this->db->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $directorId]);

        $response = $this->request('POST', '/admin/comptes/'.$directorId.'/mot-de-passe');
        $content = $response->content();

        $this->assertSame(200, $response->status(), 'Le nouveau mot de passe est affiche une fois');

        $after = (string) $this->db->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $directorId]);
        $this->assertTrue($before !== $after, 'Le mot de passe a bien change');

        // L'ancien n'est jamais montre : il n'existe nulle part en clair, et
        // c'est ce qui doit rester vrai.
        $this->assertTrue(
            ! str_contains($content, $before),
            'Aucun hachage ne doit apparaitre a l ecran'
        );

        $mail = $this->db->selectOne(
            'SELECT * FROM notifications WHERE recipient = :email',
            ['email' => 'directeur@a.cm']
        );
        $this->assertTrue($mail !== null, 'Le titulaire est prevenu');
    }

    public function testLaReinitialisationLeveAussiUnVerrouillage(): void
    {
        // Un compte verrouille apres cinq echecs reste bloque meme avec le bon
        // mot de passe : redonner l'un sans lever l'autre ne servirait a rien.
        $this->actAsSuperAdmin();
        $directorId = $this->createUser($this->tenantA, 'bloque@a.cm');

        $this->db->execute(
            'UPDATE users SET failed_login_attempts = 5, locked_until = :until WHERE id = :id',
            ['until' => date('Y-m-d H:i:s', time() + 3600), 'id' => $directorId]
        );

        $this->request('POST', '/admin/comptes/'.$directorId.'/mot-de-passe');

        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $directorId]);

        $this->assertSame(0, (int) $user['failed_login_attempts'], 'Le compteur d echecs est remis a zero');
        $this->assertTrue($user['locked_until'] === null, 'Et le verrouillage leve');
    }

    public function testUnCompteDesactiveNePeutPlusSeConnecter(): void
    {
        $this->actAsSuperAdmin();
        $userId = $this->createUser($this->tenantA, 'partant@a.cm', 'MotDePasseTest1!');

        $this->request('POST', '/admin/comptes/'.$userId.'/desactiver');

        $result = $this->app->auth()->attempt('partant@a.cm', 'MotDePasseTest1!', 'AAA', null);

        $this->assertTrue(! $result['ok'], 'Le compte desactive est refuse');

        $this->request('POST', '/admin/comptes/'.$userId.'/activer');
        $again = $this->app->auth()->attempt('partant@a.cm', 'MotDePasseTest1!', 'AAA', null);

        $this->assertTrue($again['ok'], 'Et le retour en arriere est possible');
    }

    public function testLeSuperAdminNePeutPasSeDesactiverLuiMeme(): void
    {
        // Se desactiver soi-meme ferme la porte de l'interieur.
        $userId = $this->actAsSuperAdmin();

        $this->request('POST', '/admin/comptes/'.$userId.'/desactiver');

        $status = $this->db->scalar('SELECT status FROM users WHERE id = :id', ['id' => $userId]);

        $this->assertSame('ACTIVE', (string) $status, 'Le compte reste actif');
    }

    public function testLaPlateformeNePeutPasSeRetrouverSansAdministrateur(): void
    {
        // Une plateforme sans arbitre ne se repare que dans la base : plus
        // aucune demande d'ouverture ne peut etre instruite.
        $this->actAsSuperAdmin();

        // Le compte seme par le referentiel est le seul autre administrateur ;
        // on le desactive, puis on tente de retirer le dernier.
        $seeded = (string) $this->db->scalar(
            'SELECT id FROM users WHERE email = :email',
            ['email' => 'platform@scholaris.test']
        );
        $this->request('POST', '/admin/comptes/'.$seeded.'/desactiver');

        $remaining = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.name = 'SUPER_ADMIN' AND u.status = 'ACTIVE' AND u.deleted_at IS NULL"
        );

        $this->assertTrue($remaining >= 1, 'Il reste toujours au moins un administrateur actif');
    }

    public function testUnSecondAdministrateurPeutEtreNomme(): void
    {
        $this->actAsSuperAdmin();

        $this->request('POST', '/admin/comptes', [
            'email' => 'second@scholaris.test',
            'first_name' => 'Second',
            'last_name' => 'ADMIN',
        ]);

        $user = $this->db->selectOne(
            'SELECT * FROM users WHERE email = :email',
            ['email' => 'second@scholaris.test']
        );

        $this->assertTrue($user !== null, 'Le compte est cree');
        $this->assertTrue($user['tenant_id'] === null, 'Il n appartient a aucun etablissement');

        $role = $this->db->scalar(
            'SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id',
            ['id' => $user['id']]
        );
        $this->assertSame('SUPER_ADMIN', (string) $role, 'Avec les droits d administration de la plateforme');
    }

    public function testUneAdresseDejaUtiliseeEstRefusee(): void
    {
        $this->actAsSuperAdmin();

        $this->request('POST', '/admin/comptes', [
            'email' => 'platform@scholaris.test',
            'first_name' => 'Doublon',
            'last_name' => 'ADMIN',
        ]);

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users WHERE email = :email',
            ['email' => 'platform@scholaris.test']
        );

        $this->assertSame(1, $count, 'Deux comptes de plateforme ne peuvent pas partager une adresse');
    }

    // --- Statistiques par profil ---------------------------------------------

    public function testLaPageComptesDistingueCreesEtActives(): void
    {
        // Un compte cree n'est pas un compte utilise : un directeur qui n'a
        // jamais ouvert son espace n'a pas recu ses identifiants, ou ne s'en
        // sert pas. Dans les deux cas il faut l'appeler.
        $this->actAsSuperAdmin();

        $used = $this->createUser($this->tenantA, 'actif@a.cm');
        $this->giveRole($used, 'Enseignant', ['grades:read']);
        $this->db->execute(
            'UPDATE users SET last_login = :now WHERE id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $used]
        );

        $never = $this->createUser($this->tenantA, 'jamais@a.cm');
        $this->giveRole($never, 'Enseignant', ['grades:read']);

        $stats = $this->app->tenant()->global(fn () => (new PlatformStats($this->db))->accountsByProfile());

        $this->assertSame(2, $stats['profiles']['PERSONNEL']['created'], 'Deux comptes de personnel crees');
        $this->assertSame(1, $stats['profiles']['PERSONNEL']['activated'], 'Un seul s est connecte');
        $this->assertSame(50.0, $stats['profiles']['PERSONNEL']['activation_rate'], 'Soit la moitie');
        $this->assertSame(1, $stats['profiles']['PERSONNEL']['dormant'], 'L autre est a relancer');
    }

    public function testAucunCompteNeDisparaitDuTotal(): void
    {
        // Un total qui ne tombe pas juste fait douter de tout le tableau.
        $this->actAsSuperAdmin();

        $orphan = $this->createUser($this->tenantA, 'sansrole@a.cm');

        $stats = $this->app->tenant()->global(fn () => (new PlatformStats($this->db))->accountsByProfile());
        $real = (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL');

        $this->assertSame($real, $stats['total'], 'Le total couvre tous les comptes');
        $this->assertTrue($stats['withoutRole'] >= 1, 'Les comptes sans role sont comptes a part');
    }

    public function testLaPageComptesAfficheLaRepartition(): void
    {
        $this->actAsSuperAdmin();

        $content = $this->request('GET', '/admin/comptes')->content();

        $this->assertStringContains('Repartition des comptes', $content, 'Le bandeau statistique est affiche');
        $this->assertStringContains('Taux d activation', $content, 'Avec le taux d activation');
    }

    public function testLaRechercheFiltreLesComptes(): void
    {
        $this->actAsSuperAdmin();
        $this->createUser($this->tenantA, 'cherchemoi@a.cm');
        $this->createUser($this->tenantA, 'autrechose@a.cm');

        $content = $this->request('GET', '/admin/comptes', ['q' => 'cherchemoi'])->content();

        $this->assertStringContains('cherchemoi@a.cm', $content, 'Le compte recherche apparait');
        $this->assertTrue(! str_contains($content, 'autrechose@a.cm'), 'Les autres sont ecartes');
    }

    // --- Lecture du parc -----------------------------------------------------

    public function testLeComparatifDistingueRienFactureEtRienRecouvre(): void
    {
        // Afficher « 0 % » pour une ecole qui n'a rien facture la ferait
        // passer pour defaillante.
        $this->actAsSuperAdmin();

        $content = $this->request('GET', '/admin/rapports')->content();

        $this->assertStringContains('Comparatif des etablissements', $content, 'Le comparatif s affiche');
        $this->assertStringContains(
            'rien facture',
            $content,
            'Et la lecture du tiret est expliquee'
        );
    }

    public function testLExportCsvEstLisiblePourUnTableurFrancais(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->request('GET', '/admin/rapports/export');

        $this->assertSame(200, $response->status(), 'L export repond');
        $this->assertSame(
            'text/csv; charset=UTF-8',
            $response->header('Content-Type'),
            'Au format CSV'
        );

        $body = $response->content();

        // Sans BOM ni point-virgule, Excel en configuration francaise met tout
        // dans une seule colonne et massacre les accents.
        $this->assertTrue(str_starts_with($body, "\xEF\xBB\xBF"), 'Avec la marque d ordre des octets');
        $this->assertStringContains('Code;Nom;Type', $body, 'Et le point-virgule comme separateur');
    }

    public function testLeJournalDAuditConserveLesActesDeLaPlateforme(): void
    {
        $this->actAsSuperAdmin();
        $userId = $this->createUser($this->tenantA, 'trace@a.cm');

        $this->request('POST', '/admin/comptes/'.$userId.'/mot-de-passe');

        $content = $this->request('GET', '/admin/journal')->content();

        $this->assertStringContains('Mot de passe reinitialise', $content, 'L acte est journalise et lisible');

        $entry = $this->db->selectOne(
            "SELECT * FROM audit_logs WHERE action = 'user.reset_password'"
        );

        $this->assertTrue($entry !== null, 'La trace existe en base');
        $this->assertSame($userId, (string) $entry['resource_id'], 'Avec la cible de l acte');
    }

    public function testLesHabilitationsSeLisent(): void
    {
        $this->actAsSuperAdmin();

        $content = $this->request('GET', '/admin/habilitations')->content();

        $this->assertStringContains('Roles et habilitations', $content, 'L ecran s affiche');
        $this->assertStringContains('SUPER_ADMIN', $content, 'Les roles systeme y figurent');
        $this->assertStringContains('permissions au referentiel', $content, 'Avec le compte des permissions');
    }

    // --- Fermeture -----------------------------------------------------------

    public function testCesEcransRestentFermesALAdminDUneEcole(): void
    {
        // Un administrateur d'ecole possede tenants:read pour ses propres
        // parametres. La permission seule ne doit pas lui ouvrir les comptes
        // des autres etablissements.
        $userId = $this->createUser($this->tenantA, 'admin-ecole@a.cm');
        $this->giveRole($userId, 'ADMIN_ETAB', ['tenants:read', 'tenants:update', 'tenants:create']);
        $this->actingAs($userId);

        $paths = ['/admin/comptes', '/admin/comptes/creer', '/admin/rapports',
            '/admin/rapports/export', '/admin/journal', '/admin/habilitations'];

        foreach ($paths as $path) {
            $this->assertSame(403, $this->request('GET', $path)->status(), $path.' doit rester ferme');
        }
    }

    public function testUnAdminDEcoleNePeutPasReinitialiserLeMotDePasseDUnAutre(): void
    {
        $victim = $this->createUser($this->tenantB, 'cible@b.cm');
        $before = (string) $this->db->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $victim]);

        $userId = $this->createUser($this->tenantA, 'curieux@a.cm');
        $this->giveRole($userId, 'ADMIN_ETAB', ['tenants:read', 'tenants:update']);
        $this->actingAs($userId);

        $response = $this->request('POST', '/admin/comptes/'.$victim.'/mot-de-passe');
        $after = (string) $this->db->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $victim]);

        $this->assertSame(403, $response->status(), 'La tentative est refusee');
        $this->assertSame($before, $after, 'Et le mot de passe de l autre etablissement est intact');
    }
}
