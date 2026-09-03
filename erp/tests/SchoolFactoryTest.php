<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use RuntimeException;
use Scholaris\Database\Seeder;
use Scholaris\Database\SchoolFactory;
use Scholaris\Tenant\TenantContext;

/**
 * Creation d'un etablissement et de son chef d'etablissement.
 *
 * Ce qui compte ici : la structure posee doit correspondre au type. Une ecole
 * primaire ouverte sur des classes de terminale serait inutilisable, et son
 * directeur ne doit avoir de pouvoir que sur sa propre ecole.
 */
final class SchoolFactoryTest extends TestCase
{
    private function factory(): SchoolFactory
    {
        // Le referentiel des roles doit preexister : le chef d'etablissement
        // recoit le role systeme "Admin Etablissement".
        $seeder = new Seeder($this->db, new TenantContext(), $this->basePath());
        $seeder->run('admin@scholaris.test', 'MotDePasseTest1!');

        return new SchoolFactory($this->db, new TenantContext());
    }

    public function testUneEcolePrimaireRecoitSILaCM2(): void
    {
        $result = $this->factory()->create(
            'EPC', 'EP CEKANDE', 'PRIMAIRE', 'directeur@epc.cm', 'PALOU', 'MARTIN'
        );

        $levels = $this->db->select(
            'SELECT l.code FROM levels l
             WHERE l.tenant_id = :tenant ORDER BY l.sort_order',
            ['tenant' => $result['tenant_id']]
        );

        $codes = array_column($levels, 'code');

        $this->assertSame(['SIL', 'CP', 'CE1', 'CE2', 'CM1', 'CM2'], $codes, 'Le primaire va de la SIL au CM2');
        $this->assertTrue(! in_array('TLE', $codes, true), 'Et surement pas jusqu a la terminale');
    }

    public function testUnLyceeRecoitLesDeuxCycles(): void
    {
        $result = $this->factory()->create(
            'LYC', 'Lycee Test', 'LYCEE_GENERAL', 'proviseur@lyc.cm', 'Jean', 'ATANGANA'
        );

        $codes = array_column($this->db->select(
            'SELECT code FROM levels WHERE tenant_id = :tenant ORDER BY sort_order',
            ['tenant' => $result['tenant_id']]
        ), 'code');

        $this->assertSame(7, count($codes), 'Un lycee general couvre la 6eme a la terminale');
        $this->assertTrue(in_array('TLE', $codes, true), 'Terminale comprise');
    }

    public function testLeChefDEtablissementEstAdministrateurDeSaSeuleEcole(): void
    {
        $result = $this->factory()->create(
            'EPC', 'EP CEKANDE', 'PRIMAIRE', 'palou@epc.cm', 'PALOU', 'MARTIN'
        );

        $user = $this->db->selectOne(
            'SELECT u.id, u.tenant_id, r.name AS role
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.email = :email',
            ['email' => 'palou@epc.cm']
        );

        $this->assertTrue($user !== null, 'Le compte du directeur existe');
        $this->assertSame($result['tenant_id'], $user['tenant_id'], 'Rattache a son etablissement');
        $this->assertSame('Admin Établissement', $user['role'], 'Avec les droits d administration de son ecole');
    }

    public function testLeMotDePasseInitialNEstPasStockeEnClair(): void
    {
        $result = $this->factory()->create(
            'EPC', 'EP CEKANDE', 'PRIMAIRE', 'palou@epc.cm', 'PALOU', 'MARTIN'
        );

        $hash = $this->db->scalar('SELECT password_hash FROM users WHERE email = :email', ['email' => 'palou@epc.cm']);

        $this->assertTrue($hash !== $result['password'], 'Le mot de passe ne doit jamais etre stocke tel quel');
        $this->assertTrue(password_verify($result['password'], (string) $hash), 'Mais il doit permettre de se connecter');
    }

    public function testDeuxEtablissementsNePeuventPartagerLeMemeCode(): void
    {
        $factory = $this->factory();
        $factory->create('EPC', 'EP CEKANDE', 'PRIMAIRE', 'a@epc.cm', 'PALOU', 'MARTIN');

        $caught = null;

        try {
            $factory->create('EPC', 'Autre ecole', 'PRIMAIRE', 'b@epc.cm', 'Autre', 'DIRECTEUR');
        } catch (RuntimeException $e) {
            $caught = $e->getMessage();
        }

        $this->assertTrue($caught !== null, 'Un code deja pris doit etre refuse');
    }

    public function testLeSuperAdminNestRattacheAAucunEtablissement(): void
    {
        // Il arbitre les demandes de creation : lui donner une ecole
        // reviendrait a en faire le directeur de l'une d'elles.
        $this->factory();

        $tenantId = $this->db->scalar(
            'SELECT tenant_id FROM users WHERE email = :email',
            ['email' => 'admin@scholaris.test']
        );

        $this->assertTrue($tenantId === null, 'Le Super Admin n appartient a aucun etablissement');
    }

    public function testUnSuperAdminRattacheAUneEcoleEnEstDetache(): void
    {
        // Une installation ancienne a pu rattacher le compte de plateforme a
        // une ecole. Il en affiche alors le nom partout, et surtout se retrouve
        // soumis au filtrage par etablissement : il cesse de voir les demandes
        // des autres, ce qui est pourtant son seul travail. Rejouer le seed
        // doit reparer cela.
        $seeder = new Seeder($this->db, new TenantContext(), $this->basePath());
        $seeder->run('admin@scholaris.test', 'MotDePasseTest1!');

        $this->db->execute(
            'UPDATE users SET tenant_id = :tenant WHERE email = :email',
            ['tenant' => $this->tenantA, 'email' => 'admin@scholaris.test']
        );

        $seeder->run('admin@scholaris.test', 'MotDePasseTest1!');

        $tenantId = $this->db->scalar(
            'SELECT tenant_id FROM users WHERE email = :email',
            ['email' => 'admin@scholaris.test']
        );

        $this->assertTrue($tenantId === null, 'Le Super Admin est detache de toute ecole');
    }

    public function testLeSeedNInstallePasDEtablissementDeDemonstration(): void
    {
        $this->factory();

        $codes = array_column(
            $this->db->select('SELECT code FROM tenants ORDER BY code'),
            'code'
        );

        $this->assertTrue(
            ! in_array('DEMO', $codes, true),
            'Le referentiel seul ne doit pas creer d ecole de demonstration'
        );
    }
}
