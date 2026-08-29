<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Seeder;
use Scholaris\Platform\PlatformStats;
use Scholaris\Support\Cameroon;
use Scholaris\Tenant\TenantContext;

/**
 * Gestion du parc par le Super Admin.
 *
 * Un etablissement ne pouvait naitre que d'une demande publique : ni creation
 * directe, ni correction d'un nom, ni suspension d'une ecole qui ne paie plus.
 * Il fallait passer par la base.
 *
 * Deux garde-fous sont verifies ici avec insistance : ces ecrans restent
 * fermes a l'administrateur d'une ecole, et un etablissement qui compte des
 * eleves ne se supprime pas — un dossier scolaire ne s'efface pas sur un clic.
 */
final class PlatformCrudTest extends TestCase
{
    private function actAsSuperAdmin(): string
    {
        // Le referentiel des roles doit exister : la creation rattache le
        // responsable au role systeme "Admin Etablissement".
        (new Seeder($this->db, new TenantContext(), $this->basePath()))
            ->run('platform@scholaris.test', 'MotDePasseTest1!');

        $userId = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($userId, 'SUPER_ADMIN', ['tenants:read', 'tenants:create', 'tenants:update', 'tenants:delete']);
        $this->actingAs($userId);

        return $userId;
    }

    private function createTenantViaAdmin(array $overrides = []): void
    {
        $this->request('POST', '/admin/parc', array_merge([
            'name' => 'EP CEKANDE',
            'code' => 'EPCK',
            'type' => 'PRIMAIRE',
            'status' => 'PRIVE',
            'region' => 'NORD',
            'city' => 'Garoua',
            'director_first_name' => 'MARTIN',
            'director_last_name' => 'PALOU',
            'director_email' => 'palou@epck.cm',
        ], $overrides));
    }

    public function testLeSuperAdminOuvreUnEtablissementUtilisableImmediatement(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();

        $tenant = $this->db->selectOne('SELECT * FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->assertTrue($tenant !== null, 'L etablissement est cree');
        $this->assertSame('NORD', (string) $tenant['region'], 'Avec sa region, qui le situe sur la carte');
        $this->assertSame('Garoua', (string) $tenant['city'], 'Et sa ville');

        // Structure, annee et periode : livrer une coquille vide obligerait le
        // Super Admin a tout monter a la main derriere.
        $levels = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM levels WHERE tenant_id = :tenant',
            ['tenant' => $tenant['id']]
        );
        $this->assertSame(6, $levels, 'La structure du primaire est posee, de la SIL au CM2');

        $year = $this->db->selectOne(
            "SELECT id FROM academic_years WHERE tenant_id = :tenant AND status = 'ACTIVE'",
            ['tenant' => $tenant['id']]
        );
        $this->assertTrue($year !== null, 'Une annee scolaire est ouverte');

        $open = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM periods WHERE academic_year_id = :year AND grading_status = 'OPEN'",
            ['year' => $year['id']]
        );
        $this->assertSame(1, $open, 'Avec une sequence ouverte a la saisie');
    }

    public function testLesIdentifiantsDuResponsableSontGeneresEtTransmis(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();

        $user = $this->db->selectOne('SELECT * FROM users WHERE email = :email', ['email' => 'palou@epck.cm']);

        $this->assertTrue($user !== null, 'Le compte du responsable est cree');

        $tenantId = $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);
        $this->assertSame((string) $tenantId, (string) $user['tenant_id'], 'Rattache a son etablissement');

        $mail = $this->db->selectOne(
            'SELECT * FROM notifications WHERE recipient = :email',
            ['email' => 'palou@epck.cm']
        );

        $this->assertTrue($mail !== null, 'Un courrier lui adresse ses identifiants');
        $this->assertStringContains('/login', (string) $mail['body'], 'Avec l adresse de connexion');
    }

    public function testUnCodeDejaPrisEstRefuseSansRienCreer(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $this->createTenantViaAdmin(['name' => 'Autre ecole', 'director_email' => 'autre@epck.cm']);

        $count = (int) $this->db->scalar('SELECT COUNT(*) FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->assertSame(1, $count, 'Un code deja pris ne cree pas de doublon');
    }

    public function testUnFormulaireIncompletNeCreeRien(): void
    {
        $this->actAsSuperAdmin();

        $this->request('POST', '/admin/parc', ['name' => 'Sans responsable', 'code' => 'SANS', 'type' => 'PRIMAIRE']);

        $this->assertTrue(
            $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'SANS']) === null,
            'Un etablissement sans responsable serait inexploitable'
        );
    }

    public function testLaFicheDUnEtablissementSeCorrige(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->request('POST', '/admin/parc/'.$tenantId, [
            'name' => 'Ecole Publique de CEKANDE',
            'type' => 'PRIMAIRE',
            'status' => 'PUBLIC',
            'region' => 'EXTREME_NORD',
            'city' => 'Maroua',
            'public_enrollment_enabled' => '1',
        ]);

        $tenant = $this->db->selectOne('SELECT * FROM tenants WHERE id = :id', ['id' => $tenantId]);

        $this->assertSame('Ecole Publique de CEKANDE', (string) $tenant['name'], 'Le nom est corrige');
        $this->assertSame('EXTREME_NORD', (string) $tenant['region'], 'La region aussi');
        $this->assertSame(1, (int) $tenant['public_enrollment_enabled'], 'La pre-inscription est ouverte');
    }

    public function testUneRegionInventeeNestPasEnregistree(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->request('POST', '/admin/parc/'.$tenantId, [
            'name' => 'EP CEKANDE',
            'type' => 'PRIMAIRE',
            'region' => 'ATLANTIDE',
        ]);

        $region = $this->db->scalar('SELECT region FROM tenants WHERE id = :id', ['id' => $tenantId]);

        $this->assertTrue($region === null, 'Seules les dix regions du pays sont acceptees');
    }

    // --- Suspension ----------------------------------------------------------

    public function testUneSuspensionFermeLAccesSansRienEffacer(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->request('POST', '/admin/parc/'.$tenantId.'/suspendre', ['reason' => 'Impaye de trois mois']);

        $tenant = $this->db->selectOne('SELECT * FROM tenants WHERE id = :id', ['id' => $tenantId]);

        $this->assertSame('SUSPENDED', (string) $tenant['platform_status'], 'L etablissement est suspendu');
        $this->assertStringContains(
            'Impaye de trois mois',
            (string) $tenant['config_json'],
            'Le motif est conserve : une suspension sans raison est ingerable six mois plus tard'
        );
        $this->assertTrue($tenant['deleted_at'] === null, 'Mais rien n est efface');
    }

    public function testUneSuspensionSansMotifEstRefusee(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->request('POST', '/admin/parc/'.$tenantId.'/suspendre', ['reason' => '']);

        $status = $this->db->scalar('SELECT platform_status FROM tenants WHERE id = :id', ['id' => $tenantId]);

        $this->assertSame('ACTIVE', (string) $status, 'Une suspension doit etre motivee');
    }

    public function testUnComptteDEtablissementSuspenduNePeutPlusSeConnecter(): void
    {
        // Sans ce controle, la suspension ne serait qu'un libelle dans une
        // liste.
        $superAdminId = $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        // Mot de passe connu, pour pouvoir tenter la connexion. Le compte
        // cree par l administration doit changer ce mot de passe a la
        // premiere connexion : sans interet ici, ou seule la suspension est
        // en jeu, ce drapeau est leve pour se concentrer sur elle.
        $this->db->execute(
            'UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE email = :email',
            ['hash' => password_hash('MotDePasseTest1!', PASSWORD_BCRYPT), 'email' => 'palou@epck.cm']
        );

        $before = $this->app->auth()->attempt('palou@epck.cm', 'MotDePasseTest1!', 'EPCK', null);
        $this->assertTrue($before['ok'], 'La connexion fonctionne avant la suspension');

        // L appel direct a attempt() ci-dessus a ouvert la session applicative
        // sur ce compte, comme le ferait une vraie connexion : il faut revenir
        // au Super Admin avant de solliciter l ecran de suspension.
        $this->actingAs($superAdminId);

        $this->request('POST', '/admin/parc/'.$tenantId.'/suspendre', ['reason' => 'Impaye']);

        $after = $this->app->auth()->attempt('palou@epck.cm', 'MotDePasseTest1!', 'EPCK', null);

        $this->assertTrue(! $after['ok'], 'Et plus apres');
        $this->assertStringContains('suspendu', (string) $after['error'], 'Le message dit pourquoi');
    }

    public function testLaSuspensionSeLeve(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->request('POST', '/admin/parc/'.$tenantId.'/suspendre', ['reason' => 'Impaye']);
        $this->request('POST', '/admin/parc/'.$tenantId.'/reactiver');

        $status = $this->db->scalar('SELECT platform_status FROM tenants WHERE id = :id', ['id' => $tenantId]);

        $this->assertSame('ACTIVE', (string) $status, 'Un impaye regle doit pouvoir etre leve le lendemain');
    }

    // --- Retrait -------------------------------------------------------------

    public function testUnEtablissementAvecDesElevesNeSeSupprimePas(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->createStudent($tenantId, 'EPCK/001');

        $this->request('POST', '/admin/parc/'.$tenantId.'/supprimer', ['confirm' => 'EPCK']);

        $deleted = $this->db->scalar('SELECT deleted_at FROM tenants WHERE id = :id', ['id' => $tenantId]);

        $this->assertTrue($deleted === null, 'Un dossier scolaire ne s efface pas sur un clic');
    }

    public function testLeRetraitExigeLaSaisieDuCode(): void
    {
        // Une confirmation qui se coche par megarde n'en est pas une.
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->request('POST', '/admin/parc/'.$tenantId.'/supprimer', ['confirm' => '']);

        $deleted = $this->db->scalar('SELECT deleted_at FROM tenants WHERE id = :id', ['id' => $tenantId]);

        $this->assertTrue($deleted === null, 'Sans le code saisi, rien n est retire');
    }

    public function testUnEtablissementSansEleveSeRetireAvecSesComptes(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();
        $tenantId = (string) $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'EPCK']);

        $this->request('POST', '/admin/parc/'.$tenantId.'/supprimer', ['confirm' => 'EPCK']);

        $tenant = $this->db->selectOne('SELECT deleted_at FROM tenants WHERE id = :id', ['id' => $tenantId]);
        $this->assertTrue($tenant['deleted_at'] !== null, 'L etablissement est retire');

        // Laisser un compte actif sur un etablissement retire serait une porte
        // oubliee.
        $active = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users WHERE tenant_id = :tenant AND deleted_at IS NULL',
            ['tenant' => $tenantId]
        );

        $this->assertSame(0, $active, 'Ses comptes sont retires avec lui');
    }

    // --- Fermeture aux non-Super-Admin ---------------------------------------

    public function testCesEcransRestentFermesALAdminDUneEcole(): void
    {
        // Un administrateur d'ecole possede tenants:read et tenants:update pour
        // ses propres parametres : la permission seule ne doit pas lui ouvrir
        // le parc, ou il pourrait suspendre ses concurrents.
        $userId = $this->createUser($this->tenantA, 'admin-ecole@a.cm');
        $this->giveRole($userId, 'ADMIN_ETAB', ['tenants:read', 'tenants:update', 'tenants:create']);
        $this->actingAs($userId);

        foreach (['/admin/parc', '/admin/parc/creer', '/admin/courriers', '/admin/maintenance'] as $path) {
            $this->assertSame(403, $this->request('GET', $path)->status(), $path.' doit rester ferme');
        }

        $this->assertSame(
            403,
            $this->request('POST', '/admin/parc/'.$this->tenantB.'/suspendre', ['reason' => 'x'])->status(),
            'Et il ne doit surtout pas pouvoir suspendre un autre etablissement'
        );
    }

    public function testLaMaintenanceNAppliqueQueLesMigrationsLivrees(): void
    {
        // Cet ecran ne joue que les fichiers livres avec l application. Rien
        // de ce qui est saisi n est execute : il n y a rien a y injecter.
        $this->actAsSuperAdmin();

        $content = $this->request('GET', '/admin/maintenance')->content();

        $this->assertStringContains('013_platform.sql', $content, 'Les migrations livrees sont listees');
        $this->assertStringContains('Le schema est a jour', $content, 'Et leur etat est affiche');

        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM migrations');
        $this->request('POST', '/admin/maintenance/migrer');
        $after = (int) $this->db->scalar('SELECT COUNT(*) FROM migrations');

        $this->assertSame($before, $after, 'Rejouer une migration deja appliquee ne fait rien');
    }

    // --- Tableau de bord -----------------------------------------------------

    public function testLeTableauDeBordSalueEtSitueLeParc(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();

        $content = $this->request('GET', '/admin')->content();

        $this->assertStringContains('Bonjour', $content, 'Le Super Admin est salue');
        $this->assertStringContains('Élèves inscrits', $content, 'Les eleves du parc sont comptes');
        $this->assertStringContains('Recouvrement du mois', $content, 'Le recouvrement aussi');
        $this->assertStringContains('Le parc sur le territoire', $content, 'La carte est affichee');
        $this->assertStringContains('Nord', $content, 'Avec la region de l etablissement cree');
    }

    public function testLAccueilOuvreSurTousLesDomainesAdministres(): void
    {
        // L'ecran montrait des indicateurs sans mener nulle part : on y lisait
        // « 3 demandes en attente » sans pouvoir les instruire, et la moitie
        // des ecrans n'etait atteignable que par le menu lateral. Un tableau
        // de bord qui ne mene nulle part n'est qu'un rapport.
        $this->actAsSuperAdmin();

        $content = $this->request('GET', '/admin')->content();

        $domains = [
            '/admin/etablissements',
            '/admin/parc',
            '/admin/comptes',
            '/admin/habilitations',
            '/admin/rapports',
            '/admin/journal',
            '/admin/courriers',
            '/admin/maintenance',
        ];

        $missing = [];

        foreach ($domains as $href) {
            if (! str_contains($content, 'href="'.$href.'"')) {
                $missing[] = $href;
            }
        }

        $this->assertSame([], $missing, 'Domaines absents de l accueil : '.implode(', ', $missing));
    }

    public function testLAccueilSignaleCeQuiAppelleUneAction(): void
    {
        // L'ambre ne doit designer que ce qui attend quelqu'un : un signal
        // permanent cesse d'etre lu.
        $userId = $this->actAsSuperAdmin();

        // Un compte de plateforme n'appartient a aucun etablissement : rattache
        // a l'un d'eux, son perimetre se restreint et les demandes
        // d'ouverture — qui ne relevent d'aucune ecole — disparaissent.
        $this->db->execute('UPDATE users SET tenant_id = NULL WHERE id = :id', ['id' => $userId]);
        $this->actingAs($userId);

        $sansRien = $this->request('GET', '/admin')->content();

        // Une demande en attente doit faire apparaitre le signal.
        $this->db->execute(
            "INSERT INTO establishment_requests
                (id, name, code, type, status, director_first_name, director_last_name,
                 director_email, request_status, created_at, updated_at)
             VALUES (:id, 'Ecole', 'ATT', 'PRIMAIRE', 'PRIVE', 'Chef', 'ETAB',
                 'chef@att.cm', 'PENDING', :now, :updated)",
            ['id' => \Scholaris\Database\Table::uuid(), 'now' => date('Y-m-d H:i:s'), 'updated' => date('Y-m-d H:i:s')]
        );

        $avecDemande = $this->request('GET', '/admin')->content();

        $this->assertTrue(
            substr_count($avecDemande, 'tile--alert') > substr_count($sansRien, 'tile--alert'),
            'Une demande en attente doit se signaler sur l accueil'
        );
    }

    public function testLAccueilPorteLesChiffresDuPersonnel(): void
    {
        $this->actAsSuperAdmin();

        $content = $this->request('GET', '/admin')->content();

        $this->assertStringContains('Agents en poste', $content, 'Les effectifs figurent');
        $this->assertStringContains('Élèves par agent', $content, 'Ainsi que le taux d encadrement');
    }

    public function testLaCarteSitueChaqueEtablissementDansSaRegion(): void
    {
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin();

        $map = $this->app->tenant()->global(fn () => (new PlatformStats($this->db))->byRegion());

        $nord = null;

        foreach ($map['regions'] as $region) {
            if ($region['code'] === 'NORD') {
                $nord = $region;
            }
        }

        $this->assertTrue($nord !== null, 'Les dix regions sont presentes');
        $this->assertSame(1, count($nord['tenants']), 'L etablissement figure dans le Nord');
        $this->assertSame(10, count($map['regions']), 'Le pays compte dix regions');
    }

    public function testUnEtablissementSansRegionEstSignaleEtNonPlaceAuHasard(): void
    {
        // Le poser sur une region par defaut donnerait une carte fausse ; le
        // taire le ferait disparaitre du parc.
        $this->actAsSuperAdmin();
        $this->createTenantViaAdmin(['region' => '']);

        $map = $this->app->tenant()->global(fn () => (new PlatformStats($this->db))->byRegion());

        $this->assertTrue(
            count($map['unlocated']['tenants']) >= 1,
            'Un etablissement sans region est compte a part'
        );
    }

    public function testLaCarteTientDansSonCadre(): void
    {
        // Un point projete hors du cadre serait dessine par-dessus le reste de
        // la page.
        foreach (Cameroon::regions() as $code => $region) {
            $point = Cameroon::regionPoint($code);

            $this->assertTrue(
                $point['x'] > 0 && $point['x'] < Cameroon::WIDTH,
                $region['name'].' doit tenir dans la largeur du dessin'
            );
            $this->assertTrue(
                $point['y'] > 0 && $point['y'] < Cameroon::HEIGHT,
                $region['name'].' doit tenir dans la hauteur du dessin'
            );
        }
    }
}
