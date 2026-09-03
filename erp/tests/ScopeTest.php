<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Platform\PlatformStats;
use Scholaris\Platform\Scope;
use Scholaris\Support\Cameroon;

/**
 * Perimetre d'un compte de pilotage.
 *
 * L'habilitation reposait sur deux niveaux — un role, une permission. Cela
 * suffit pour une ecole, pas pour une plateforme nationale : un delegue
 * regional a exactement les memes permissions de lecture qu'un administrateur
 * national. Ce qui les distingue n'est pas ce qu'ils peuvent faire, mais
 * l'etendue sur laquelle ils le font.
 *
 * Ces tests portent sur cette etendue. Une fuite de perimetre ne provoque
 * aucune erreur : l'ecran s'affiche, avec les donnees d'autrui.
 */
final class ScopeTest extends TestCase
{
    /** @return array{nord: string, ouest: string} */
    private function twoRegions(): array
    {
        $nord = $this->createTenant('NRD', 'Lycee du Nord');
        $ouest = $this->createTenant('OST', 'Lycee de l Ouest');

        $this->db->execute("UPDATE tenants SET region = 'NORD' WHERE id = :id", ['id' => $nord]);
        $this->db->execute("UPDATE tenants SET region = 'OUEST' WHERE id = :id", ['id' => $ouest]);

        return ['nord' => $nord, 'ouest' => $ouest];
    }

    public function testUnPerimetreRegionalNeCompteQueSaRegion(): void
    {
        $regions = $this->twoRegions();

        $this->createStudent($regions['nord'], 'N/001');
        $this->createStudent($regions['nord'], 'N/002');
        $this->createStudent($regions['ouest'], 'O/001');

        $national = (new PlatformStats($this->db, Scope::platform()))->overview();
        $regional = (new PlatformStats($this->db, Scope::region('NORD')))->overview();

        $this->assertSame(3, $national['students']['total'], 'Le national voit tout le pays');
        $this->assertSame(2, $regional['students']['total'], 'Le regional ne voit que sa region');
    }

    public function testUnPerimetreRegionalNeCompteQueSesEtablissements(): void
    {
        $this->twoRegions();

        $regional = (new PlatformStats($this->db, Scope::region('NORD')))->overview();

        $this->assertSame(1, $regional['tenants']['total'], 'Un seul etablissement dans le Nord');
    }

    public function testLaCarteNAfficheQueLesEtablissementsDuPerimetre(): void
    {
        $this->twoRegions();

        $map = (new PlatformStats($this->db, Scope::region('NORD')))->byRegion();
        $counts = [];

        foreach ($map['regions'] as $region) {
            $counts[$region['code']] = count($region['tenants']);
        }

        $this->assertSame(1, $counts['NORD'], 'Le Nord porte son etablissement');
        $this->assertSame(0, $counts['OUEST'], 'L Ouest reste vide pour un delegue du Nord');
    }

    public function testLesComptesSontEuxAussiCadres(): void
    {
        $regions = $this->twoRegions();

        $nordUser = $this->createUser($regions['nord'], 'prof@nord.cm');
        $this->giveRole($nordUser, 'Enseignant', ['grades:read']);

        $ouestUser = $this->createUser($regions['ouest'], 'prof@ouest.cm');
        $this->giveRole($ouestUser, 'Enseignant', ['grades:read']);

        $regional = (new PlatformStats($this->db, Scope::region('NORD')))->accountsByProfile();

        $this->assertSame(1, $regional['profiles']['PERSONNEL']['created'], 'Un seul enseignant dans le Nord');
    }

    public function testAucuneRequeteDePilotageNEchappeAuPerimetre(): void
    {
        // Le vrai risque n'est pas qu'un cadrage soit faux, mais qu'une
        // requete l'oublie : l'ecran s'affiche alors normalement, avec les
        // donnees d'autres regions, et personne ne s'en apercoit. Ce controle
        // lit le fichier plutot que d'esperer qu'on y pense.
        $source = (string) file_get_contents($this->basePath().'/src/Platform/PlatformStats.php');
        $lines = explode("\n", $source);

        $problems = [];
        $open = null;
        $buffer = '';

        foreach ($lines as $number => $line) {
            if ($open === null && preg_match('/\$this->db->(select|scalar)\(/', $line) === 1) {
                $open = $number + 1;
                $buffer = '';
            }

            if ($open === null) {
                continue;
            }

            $buffer .= $line."\n";

            // Fin de l'appel : une parenthese fermante en fin de ligne.
            if (preg_match('/\);\s*$/', $line) === 1 || preg_match('/\),\s*$/', $line) === 1) {
                // Deux formes admises, et deux seulement : withScope() pose
                // les parametres du perimetre, prepare() rend la requete et
                // ces memes parametres. Toute autre construction est refusee,
                // pour que le controle ne devienne pas contournable.
                if (! str_contains($buffer, 'withScope') && ! str_contains($buffer, '$this->prepare(')) {
                    $problems[] = 'ligne '.$open;
                }

                $open = null;
            }
        }

        $this->assertSame(
            [],
            $problems,
            'Requete de pilotage sans perimetre : '.implode(', ', $problems)
        );
    }

    public function testUnPerimetreNationalNeRestreintRien(): void
    {
        $this->twoRegions();

        $national = (new PlatformStats($this->db, Scope::platform()))->overview();

        $this->assertSame(4, $national['tenants']['total'], 'Les deux regions plus les deux du socle de test');
    }

    // --- Resolution depuis le compte ------------------------------------------

    public function testUnComptePlateformeSansPerimetreDeclareEstNational(): void
    {
        // C'est le cas du Super Admin d'origine : retomber sur un perimetre
        // vide le priverait de tout au lieu de tout lui donner.
        $scope = Scope::forUser(['tenant_id' => null, 'scope_type' => null, 'scope_value' => null]);

        $this->assertTrue($scope->isNational(), 'Aucun perimetre declare vaut perimetre national');
    }

    public function testUnCompteRattacheAUneEcoleNeVoitQueLaSienne(): void
    {
        $scope = Scope::forUser(['tenant_id' => 'abc', 'scope_type' => null, 'scope_value' => null]);

        $this->assertSame(Scope::TENANT, $scope->type(), 'Son etablissement fait son perimetre');
        $this->assertSame('abc', $scope->value(), 'Et c est bien le sien');
    }

    public function testUneRegionInventeeNeDonnePasUnAccesNational(): void
    {
        // Une valeur de perimetre erronee doit restreindre, jamais elargir :
        // l'inverse ferait d'une faute de frappe une elevation de privilege.
        $scope = Scope::forUser([
            'tenant_id' => null,
            'scope_type' => 'REGION',
            'scope_value' => 'ATLANTIDE',
        ]);

        $this->assertTrue($scope->isNational(), 'Le perimetre retombe sur le compte, sans region reconnue');
    }

    public function testLePerimetreSaitCeQuIlCouvre(): void
    {
        $nord = Scope::region('NORD');

        $this->assertTrue($nord->covers(['region' => 'NORD']), 'Une ecole du Nord est couverte');
        $this->assertTrue(! $nord->covers(['region' => 'OUEST']), 'Une ecole de l Ouest ne l est pas');
        $this->assertTrue(Scope::platform()->covers(['region' => 'OUEST']), 'Le national couvre tout');
    }

    // --- Tutelle ministerielle ------------------------------------------------

    public function testUnePerimetreMinisterielTraverseLesRegions(): void
    {
        // Un ministere couvre une tutelle, qui traverse toutes les regions
        // mais ne concerne qu'une partie des etablissements de chacune. Les
        // deux decoupages se croisent sans se confondre.
        $primaireNord = $this->createTenant('PN', 'Ecole du Nord');
        $lyceeNord = $this->createTenant('LN', 'Lycee du Nord');
        $primaireOuest = $this->createTenant('PO', 'Ecole de l Ouest');

        $this->db->execute(
            "UPDATE tenants SET region = 'NORD', ministry = 'MINEDUB' WHERE id = :id",
            ['id' => $primaireNord]
        );
        $this->db->execute(
            "UPDATE tenants SET region = 'NORD', ministry = 'MINESEC' WHERE id = :id",
            ['id' => $lyceeNord]
        );
        $this->db->execute(
            "UPDATE tenants SET region = 'OUEST', ministry = 'MINEDUB' WHERE id = :id",
            ['id' => $primaireOuest]
        );

        $minedub = (new PlatformStats($this->db, Scope::ministry('MINEDUB')))->overview();

        $this->assertSame(2, $minedub['tenants']['total'], 'Les deux ecoles primaires, dans deux regions');
    }

    public function testUnMinistereNeVoitPasLesEtablissementsDUneAutreTutelle(): void
    {
        $lycee = $this->createTenant('LYC', 'Lycee');
        $this->db->execute("UPDATE tenants SET ministry = 'MINESEC' WHERE id = :id", ['id' => $lycee]);
        $this->createStudent($lycee, 'L/001');

        $minedub = (new PlatformStats($this->db, Scope::ministry('MINEDUB')))->overview();

        $this->assertSame(0, $minedub['students']['total'], 'Aucun eleve du secondaire pour l education de base');
    }

    public function testLaTutelleSeDeduitDuTypeDEtablissement(): void
    {
        // Sert de proposition a la creation : un lycee technique releve du
        // MINESEC, un centre de formation du MINEFOP.
        $this->assertSame('MINEDUB', Cameroon::ministryForType('PRIMAIRE'), 'Le primaire releve du MINEDUB');
        $this->assertSame('MINESEC', Cameroon::ministryForType('LYCEE_TECHNIQUE'), 'Le technique du MINESEC');
        $this->assertSame('MINESUP', Cameroon::ministryForType('SUPERIEUR'), 'Le superieur du MINESUP');
        $this->assertSame('MINEFOP', Cameroon::ministryForType('CENTRE_FORMATION'), 'La formation du MINEFOP');
    }

    public function testLeReferentielDesDepartementsEstComplet(): void
    {
        // Referentiel plutot que champ libre : « Mfoundi » saisi de trois
        // facons dans trois etablissements donnerait trois departements, et
        // aucun rapport departemental ne tomberait juste.
        $this->assertSame(58, count(Cameroon::departments()), 'Le pays compte cinquante-huit departements');
        $this->assertSame('CENTRE', Cameroon::regionOfDepartment('Mfoundi'), 'Le Mfoundi est au Centre');
        $this->assertSame('LITTORAL', Cameroon::regionOfDepartment('Wouri'), 'Le Wouri au Littoral');
        $this->assertTrue(! Cameroon::isDepartment('Atlantide'), 'Un departement invente est refuse');
    }

    public function testChaqueDepartementAppartientAUneRegionConnue(): void
    {
        foreach (Cameroon::departmentsByRegion() as $region => $departments) {
            $this->assertTrue(
                Cameroon::isRegion($region),
                'Le departement de '.$departments[0].' est rattache a une region inconnue : '.$region
            );
        }
    }

    // --- Attribution ---------------------------------------------------------

    private function actAsSuperAdmin(): string
    {
        (new \Scholaris\Database\Seeder($this->db, new \Scholaris\Tenant\TenantContext(), $this->basePath()))
            ->run('platform@scholaris.test', 'MotDePasseTest1!');

        $userId = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($userId, 'SUPER_ADMIN', [
            'tenants:read', 'tenants:create', 'users:assign-roles', 'users:delete',
        ]);
        $this->actingAs($userId);

        return $userId;
    }

    public function testUnDelegueRegionalSeNommeDepuisLEcran(): void
    {
        // Le perimetre cadrait deja toutes les lectures, mais rien ne
        // permettait de l'attribuer : un delegue ne pouvait exister qu'en
        // modifiant la base a la main.
        $this->actAsSuperAdmin();

        $this->request('POST', '/admin/comptes', [
            'email' => 'delegue.nord@scholaris.test',
            'first_name' => 'Delegue',
            'last_name' => 'NORD',
            'scope_type' => 'REGION',
            'scope_value' => 'NORD',
        ]);

        $user = $this->db->selectOne(
            'SELECT * FROM users WHERE email = :email',
            ['email' => 'delegue.nord@scholaris.test']
        );

        $this->assertTrue($user !== null, 'Le compte est cree');
        $this->assertSame('REGION', (string) $user['scope_type'], 'Avec son etendue');
        $this->assertSame('NORD', (string) $user['scope_value'], 'Et sa region');

        // Un delegue consulte son territoire ; il n'administre pas la
        // plateforme et ne cree pas d'etablissement.
        $role = $this->db->scalar(
            'SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id',
            ['id' => $user['id']]
        );
        $this->assertSame('Délégué', (string) $role, 'Il recoit le role de delegue, pas SUPER_ADMIN');
    }

    public function testLePerimetreDUnCompteSeCorrige(): void
    {
        $this->actAsSuperAdmin();

        $delegate = $this->createUser($this->tenantA, 'delegue@scholaris.test');
        $this->db->execute('UPDATE users SET tenant_id = NULL WHERE id = :id', ['id' => $delegate]);

        $this->request('POST', '/admin/comptes/'.$delegate.'/perimetre', [
            'scope_type' => 'REGION',
            'scope_value' => 'OUEST',
        ]);

        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $delegate]);

        $this->assertSame('OUEST', (string) $user['scope_value'], 'Le perimetre est applique');
    }

    public function testUnAdminDEcoleNePeutPasRecevoirLePerimetreNational(): void
    {
        // Sinon un formulaire suffirait a faire d'un administrateur d'ecole un
        // administrateur national.
        $this->actAsSuperAdmin();

        $schoolAdmin = $this->createUser($this->tenantB, 'admin@b.cm');

        $this->request('POST', '/admin/comptes/'.$schoolAdmin.'/perimetre', ['scope_type' => 'PLATFORM']);

        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $schoolAdmin]);

        $this->assertSame($this->tenantB, (string) $user['tenant_id'], 'Il reste rattache a son ecole');
        $this->assertTrue($user['scope_type'] === null, 'Et ne recoit aucun perimetre national');
    }

    public function testUneRegionInconnueEstRefusee(): void
    {
        $this->actAsSuperAdmin();

        $delegate = $this->createUser($this->tenantA, 'delegue@scholaris.test');
        $this->db->execute('UPDATE users SET tenant_id = NULL WHERE id = :id', ['id' => $delegate]);

        $this->request('POST', '/admin/comptes/'.$delegate.'/perimetre', [
            'scope_type' => 'REGION',
            'scope_value' => 'ATLANTIDE',
        ]);

        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $delegate]);

        $this->assertTrue($user['scope_value'] === null, 'Une region inconnue n est pas enregistree');
    }

    public function testUnChangementDePerimetreEstJournalise(): void
    {
        // Elargir ou restreindre ce que quelqu un peut voir est un acte de
        // gouvernance : il doit pouvoir etre rapporte a son auteur.
        $this->actAsSuperAdmin();

        $delegate = $this->createUser($this->tenantA, 'delegue@scholaris.test');
        $this->db->execute('UPDATE users SET tenant_id = NULL WHERE id = :id', ['id' => $delegate]);

        $this->request('POST', '/admin/comptes/'.$delegate.'/perimetre', [
            'scope_type' => 'REGION',
            'scope_value' => 'NORD',
        ]);

        $entry = $this->db->selectOne("SELECT * FROM audit_logs WHERE action = 'user.scope'");

        $this->assertTrue($entry !== null, 'Le changement est journalise');
        $this->assertStringContains('NORD', (string) $entry['new_value'], 'Avec le nouveau perimetre');
    }

    // --- Etancheite ----------------------------------------------------------

    public function testUnDelegueNePeutPasEntrerDansUneEcoleHorsPerimetre(): void
    {
        // Filtrer les listes ne suffit pas : l'identifiant se devine, et c'est
        // a l'entree que l'acces est reellement ouvert.
        $regions = $this->twoRegions();

        $delegate = $this->createUser($this->tenantA, 'delegue@nord.cm');
        $this->giveRole($delegate, 'SUPER_ADMIN', ['tenants:read']);
        $this->db->execute(
            "UPDATE users SET tenant_id = NULL, scope_type = 'REGION', scope_value = 'NORD' WHERE id = :id",
            ['id' => $delegate]
        );
        $this->actingAs($delegate);

        $refused = $this->request('POST', '/admin/etablissements/'.$regions['ouest'].'/consulter');
        $allowed = $this->request('POST', '/admin/etablissements/'.$regions['nord'].'/consulter');

        $this->assertSame(403, $refused->status(), 'Une ecole d une autre region est refusee');
        $this->assertSame(302, $allowed->status(), 'Celle de sa region est ouverte');
    }

}
