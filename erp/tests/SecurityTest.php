<?php

declare(strict_types=1);

namespace Scholaris\Tests;

/**
 * Controles transversaux : authentification, verrouillage, CSRF, habilitations,
 * echappement des sorties.
 */
final class SecurityTest extends TestCase
{
    public function testUneConnexionValideOuvreLaSession(): void
    {
        $this->createUser($this->tenantA, 'directeur@a.cm');

        $response = $this->request('POST', '/login', [
            'email' => 'directeur@a.cm',
            'password' => 'Test123!',
        ]);

        $this->assertSame(302, $response->status(), 'Une connexion reussie doit rediriger');
        $this->assertSame('/dashboard', $response->header('Location'), 'Vers le tableau de bord');
        $this->assertTrue($this->app->auth()->check(), 'L utilisateur doit etre authentifie');
    }

    public function testUnMotDePasseErroneEstRefuse(): void
    {
        $this->createUser($this->tenantA, 'directeur@a.cm');

        $response = $this->request('POST', '/login', [
            'email' => 'directeur@a.cm',
            'password' => 'mauvais',
        ]);

        $this->assertSame('/login', $response->header('Location'), 'Retour au formulaire');
        $this->assertTrue(! $this->app->auth()->check(), 'Aucune session ne doit etre ouverte');
    }

    public function testLeCompteSeVerrouilleApresCinqEchecs(): void
    {
        $userId = $this->createUser($this->tenantA, 'directeur@a.cm');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->request('POST', '/login', ['email' => 'directeur@a.cm', 'password' => 'mauvais']);
        }

        $row = $this->db->selectOne('SELECT failed_login_attempts, locked_until FROM users WHERE id = :id', ['id' => $userId]);

        $this->assertSame(5, (int) ($row['failed_login_attempts'] ?? 0), 'Le compteur doit atteindre 5');
        $this->assertTrue(($row['locked_until'] ?? null) !== null, 'Le compte doit etre verrouille');

        // Meme avec le bon mot de passe, la connexion reste bloquee.
        $this->request('POST', '/login', ['email' => 'directeur@a.cm', 'password' => 'Test123!']);
        $this->assertTrue(! $this->app->auth()->check(), 'Un compte verrouille ne doit pas pouvoir se connecter');
    }

    public function testUneConnexionReussieRemetLeCompteurAZero(): void
    {
        $userId = $this->createUser($this->tenantA, 'directeur@a.cm');

        $this->request('POST', '/login', ['email' => 'directeur@a.cm', 'password' => 'mauvais']);
        $this->request('POST', '/login', ['email' => 'directeur@a.cm', 'password' => 'Test123!']);

        $row = $this->db->selectOne('SELECT failed_login_attempts FROM users WHERE id = :id', ['id' => $userId]);

        $this->assertSame(0, (int) ($row['failed_login_attempts'] ?? -1), 'Le compteur doit repartir de zero');
    }

    public function testUnEmailPresentDansDeuxEtablissementsExigeLeCode(): void
    {
        $this->createUser($this->tenantA, 'partage@ecole.cm');
        $this->createUser($this->tenantB, 'partage@ecole.cm');

        $this->request('POST', '/login', ['email' => 'partage@ecole.cm', 'password' => 'Test123!']);
        $this->assertTrue(! $this->app->auth()->check(), 'Sans code, la connexion doit etre refusee');

        $this->request('POST', '/login', [
            'email' => 'partage@ecole.cm',
            'password' => 'Test123!',
            'tenant_code' => 'BBB',
        ]);

        $this->assertTrue($this->app->auth()->check(), 'Avec le code, la connexion doit aboutir');
        $this->assertSame($this->tenantB, $this->app->tenant()->id(), 'Le bon etablissement doit etre selectionne');
    }

    public function testUnCompteDesactiveNePeutPasSeConnecter(): void
    {
        $userId = $this->createUser($this->tenantA, 'ancien@a.cm');
        $this->db->execute('UPDATE users SET status = :s WHERE id = :id', ['s' => 'INACTIVE', 'id' => $userId]);

        $this->request('POST', '/login', ['email' => 'ancien@a.cm', 'password' => 'Test123!']);

        $this->assertTrue(! $this->app->auth()->check(), 'Un compte desactive doit etre refuse');
    }

    public function testUneEcritureSansJetonCsrfEstRefusee(): void
    {
        $this->createUser($this->tenantA, 'directeur@a.cm');

        // Jeton volontairement absent : simule une requete emise par un site
        // tiers au nom de l'utilisateur.
        $response = $this->request('POST', '/login', [
            'email' => 'directeur@a.cm',
            'password' => 'Test123!',
            '_token' => '',
        ]);

        $this->assertSame(419, $response->status(), 'Une requete sans jeton CSRF doit etre rejetee');
        $this->assertTrue(! $this->app->auth()->check(), 'Aucune session ne doit etre ouverte');
    }

    public function testUnJetonCsrfIncorrectEstRefuse(): void
    {
        $this->createUser($this->tenantA, 'directeur@a.cm');

        $response = $this->request('POST', '/login', [
            'email' => 'directeur@a.cm',
            'password' => 'Test123!',
            '_token' => str_repeat('a', 64),
        ]);

        $this->assertSame(419, $response->status(), 'Un jeton errone doit etre rejete');
    }

    public function testUnePageProtegeeRedirigeVersLaConnexion(): void
    {
        $response = $this->request('GET', '/dashboard');

        $this->assertSame(302, $response->status(), 'Un visiteur anonyme doit etre redirige');
        $this->assertSame('/login', $response->header('Location'), 'Vers la page de connexion');
    }

    public function testUnePermissionManquanteDonne403(): void
    {
        $userId = $this->createUser($this->tenantA, 'sansdroit@a.cm');
        $this->giveRole($userId, 'MINIMAL', ['dashboard:read']);
        $this->actingAs($userId);

        $response = $this->request('GET', '/students');

        $this->assertSame(403, $response->status(), 'Sans students:read, l acces doit etre refuse');
    }

    public function testUnePermissionAccordeeOuvreLAcces(): void
    {
        $userId = $this->createUser($this->tenantA, 'lecteur@a.cm');
        $this->giveRole($userId, 'LECTEUR', ['students:read']);
        $this->actingAs($userId);

        $response = $this->request('GET', '/students');

        $this->assertSame(200, $response->status(), 'Avec students:read, la liste doit s afficher');
    }

    public function testLActionManageCouvreLesAutresActions(): void
    {
        $userId = $this->createUser($this->tenantA, 'gestionnaire@a.cm');
        $this->giveRole($userId, 'GESTION', ['students:manage']);
        $this->actingAs($userId);

        $this->assertTrue(
            $this->app->rbac()->allows('students:read'),
            'students:manage doit couvrir students:read'
        );
        $this->assertTrue(
            $this->app->rbac()->allows('students:create'),
            'students:manage doit couvrir students:create'
        );
        $this->assertTrue(
            $this->app->rbac()->denies('invoices:read'),
            'students:manage ne doit rien accorder sur une autre ressource'
        );
    }

    public function testLeSuperAdminTraverseLesControles(): void
    {
        $userId = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($userId, 'SUPER_ADMIN', []);
        $this->actingAs($userId);

        $this->assertTrue(
            $this->app->rbac()->allows('invoices:create'),
            'Le Super Admin doit tout pouvoir, sans permission explicite'
        );
    }

    public function testLesDonneesAffichesSontEchappees(): void
    {
        $userId = $this->createUser($this->tenantA, 'lecteur@a.cm');
        $this->giveRole($userId, 'LECTEUR', ['students:read']);

        // Nom porteur d'une charge XSS : il doit ressortir echappe dans le HTML.
        $this->createStudent($this->tenantA, 'A/XSS', '<script>alert(1)</script>');
        $this->actingAs($userId);

        $html = $this->request('GET', '/students')->content();

        $this->assertTrue(
            ! str_contains($html, '<script>alert(1)</script>'),
            'La charge ne doit jamais apparaitre telle quelle'
        );
        $this->assertStringContains(
            '&lt;script&gt;',
            $html,
            'Le nom doit apparaitre sous forme echappee'
        );
    }
}
