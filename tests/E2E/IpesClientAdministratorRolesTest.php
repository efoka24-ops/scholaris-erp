<?php

declare(strict_types=1);

namespace Tests\E2E;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Migration\Console\PilotSetupCommand;
use Scholaris\Modules\Socle\Platform\Console\GrantApplicationRolesCommand;
use Tests\E2E\Support\BuildsIpes;
use Tests\E2E\Support\RealStackTestCase;

/**
 * IPES-ADMIN (20 novembre 2026) : l'administrateur d'un client créé par l'acceptation d'une demande d'accès (super_admin) recevait
 * « Accès refusé » sur l'écran Structure de l'IPES (`GET /higher/schools` en 403) : aucun rôle IPES ne lui était donné. Il reçoit
 * désormais les rôles d'administration déclarés par le manifeste de l'application (IPES : R03 directeur académique et R20
 * administrateur fonctionnel ; primaire, secondaire, maternelle : leur rôle de direction), et la commande
 * `platform:grant-application-roles` rattrape les clients déjà acceptés. Pile réelle ; noms, adresses et numéros FICTIFS.
 */
final class IpesClientAdministratorRolesTest extends RealStackTestCase
{
    use BuildsIpes;

    private const IPES = [
        'name' => 'Institut Fictif des Métiers de Garoua', 'city' => 'Garoua', 'region' => 'Nord',
        'address' => 'Quartier fictif, Garoua', 'first' => 'Hadiza', 'last' => 'Fictive-Directrice',
        'email' => 'direction@ipes-admin-recette.test', 'phone' => '+237 699 41 00 01', 'normalized' => '+237699410001',
    ];

    private const IPES_OTHER = [
        'name' => 'Institut Fictif du Septentrion Bis', 'city' => 'Maroua', 'region' => 'Extrême-Nord',
        'address' => 'Quartier fictif, Maroua', 'first' => 'Bouba', 'last' => 'Fictif-Promoteur',
        'email' => 'direction@ipes-admin-bis.test', 'phone' => '+237 699 41 00 02', 'normalized' => '+237699410002',
    ];

    protected function setUp(): void
    {
        $this->bootRealStack(['app' => ['url' => 'https://app.scholaris.test']]);
    }

    public function testLAdministrateurDuClientOuvreLesEcransDEntreeDeLIpesApresLeModelePilote(): void
    {
        $ctx = $this->onboard(self::IPES);
        $this->pilotSetup($ctx['tenant'], 'formation-pro');

        // Écran Structure : écoles, départements, filières, niveaux et groupes installés par le modèle.
        $schools = $this->as($ctx, 'admin', 'GET', '/higher/schools')['data'];
        self::assertCount(4, $schools, (string) json_encode($schools));
        foreach (['/higher/departments', '/higher/programs', '/higher/levels', '/higher/groups'] as $path) {
            self::assertNotEmpty($this->as($ctx, 'admin', 'GET', $path)['data'], $path);
        }
        // Paramétrage académique, autorisations, années et périodes, enseignants, inscriptions.
        foreach (['/higher/authorizations', '/higher/regulations', '/higher/calendar-entries', '/academic-years', '/periods', '/higher/teaching/teachers', '/higher/enrolments'] as $path) {
            $this->as($ctx, 'admin', 'GET', $path);
        }

        // Rôles reçus : dans l'établissement IPES du client seulement ; pas de tuile « Tout le groupe ».
        $roles = $this->rolesOf($ctx['tenant'], $ctx['admin_person']);
        self::assertSame([
            'higher_admin_fonct|establishment|' . $ctx['establishment'],
            'higher_dir_academique|establishment|' . $ctx['establishment'],
            'super_admin|tenant|' . $ctx['tenant'],
        ], $roles);
        $tiles = $this->ok('GET', '/me/contexts', $ctx['admin'])['data'];
        self::assertNotContains('group', array_column($tiles, 'kind'));
        $codes = array_column($tiles[0]['roles'], 'code');
        self::assertContains('higher_dir_academique', $codes);
        self::assertContains('higher_admin_fonct', $codes);

        // Écriture de la structure (R20) possible ; aucune affectation dans un autre client.
        $this->as($ctx, 'admin', 'POST', '/higher/schools', ['code' => 'NOUV', 'name' => 'École fictive nouvelle']);
        $other = $this->pdo()->prepare('SELECT COUNT(*) FROM role_assignments WHERE person_id = ? AND tenant_id <> ?');
        $other->execute([$ctx['admin_person'], $ctx['tenant']]);
        self::assertSame(0, (int) $other->fetchColumn());
    }

    public function testLeRattrapageNeDonneLeRoleQuAuxAdministrateursSansRoleDeLApplication(): void
    {
        // Client accepté avant la correction : son administrateur n'a que super_admin. Un enseignant existe déjà.
        $legacy = $this->onboard(self::IPES);
        $teacher = $this->provisionUser($legacy['admin'], $legacy['establishment'], 'Moussa', 'Fictif-Enseignant', 'enseignant')['person_id'];
        $this->forget($legacy['tenant'], $legacy['admin_person'], ['higher_admin_fonct', 'higher_dir_academique']);
        self::assertSame(['super_admin|tenant|' . $legacy['tenant']], $this->rolesOf($legacy['tenant'], $legacy['admin_person']));
        [$denied] = $this->api('GET', '/higher/schools', $legacy['admin'], null, $legacy['establishment']);
        self::assertSame(403, $denied->getStatusCode(), 'Défaut reproduit : aucun rôle IPES, écran Structure refusé.');

        // Autre client : son administrateur s'est déjà donné un rôle IPES (R20 seul) : le rattrapage n'y touche pas.
        $chosen = $this->onboard(self::IPES_OTHER);
        $this->forget($chosen['tenant'], $chosen['admin_person'], ['higher_dir_academique']);
        $chosenBefore = $this->rolesOf($chosen['tenant'], $chosen['admin_person']);
        $teacherBefore = $this->rolesOf($legacy['tenant'], $teacher);

        $first = $this->grantCommand();
        self::assertStringContainsString('2 rôle(s) attribué(s)', $first);
        self::assertSame([
            'higher_admin_fonct|establishment|' . $legacy['establishment'],
            'higher_dir_academique|establishment|' . $legacy['establishment'],
            'super_admin|tenant|' . $legacy['tenant'],
        ], $this->rolesOf($legacy['tenant'], $legacy['admin_person']));
        self::assertSame($chosenBefore, $this->rolesOf($chosen['tenant'], $chosen['admin_person']), 'Administrateur ayant déjà un rôle IPES : inchangé.');
        self::assertSame($teacherBefore, $this->rolesOf($legacy['tenant'], $teacher), 'Autres utilisateurs : inchangés.');
        self::assertSame(200, $this->api('GET', '/higher/schools', $legacy['admin'], null, $legacy['establishment'])[0]->getStatusCode());

        // Idempotente : une seconde exécution n'attribue rien.
        $total = $this->assignmentCount();
        self::assertStringContainsString('0 rôle(s) attribué(s)', $this->grantCommand());
        self::assertSame($total, $this->assignmentCount());
        $audit = $this->pdo()->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = 'identity.role_assigned' AND object_id = ?");
        $audit->execute([$legacy['tenant'], $legacy['admin_person']]);
        self::assertGreaterThanOrEqual(3, (int) $audit->fetchColumn());
    }

    /**
     * Même défaut pour les applications privées primaire, secondaire et maternelle : leur administrateur reçoit le rôle de direction.
     */
    public function testLesAutresApplicationsDonnentLeurRoleDeDirectionALAdministrateur(): void
    {
        $cases = [
            ['primary', 'primary', null, 'primary_directeur', 'primary.dashboard.read'],
            ['secondary', 'secondary', 'college_prive', 'sec_chef_etablissement', 'secondary.settings.read'],
            ['nursery', 'nursery', null, 'nursery_directeur', 'nursery.dashboard.read'],
        ];
        $checker = $this->kernel->container()->get(PermissionChecker::class);
        foreach ($cases as $i => [$app, $type, $subtype, $role, $permission]) {
            $ip = '198.51.100.' . (120 + $i);
            [$submitted] = $this->publicCall('POST', '/public/access-requests', [
                'institution_name' => 'Établissement fictif ' . $app, 'target_type' => $type, 'city' => 'Garoua', 'region' => 'Nord',
                'contact_first_name' => 'Awa', 'contact_last_name' => 'Fictive-' . $app, 'contact_email' => 'direction@' . $app . '-recette.test',
                'contact_phone' => sprintf('+237 699 42 00 %02d', $i + 1), 'app_key' => $app, 'establishment_subtype' => $subtype,
            ], $ip);
            self::assertSame(202, $submitted->getStatusCode(), (string) $submitted->getBody());
            $platform = $this->platform();
            $pending = $this->ok('GET', '/platform/access-requests?filter[status]=pending', $platform)['data'];
            $accepted = $this->ok('POST', '/platform/access-requests/' . $pending[0]['id'] . '/accept', $platform, ['force_reason' => 'Établissement pilote fictif.'])['data'];
            $tenant = (string) $accepted['tenant_id'];
            $establishment = (string) $accepted['establishment_id'];
            $person = (string) $accepted['administrator']['person_id'];
            $user = (string) $accepted['administrator']['user_id'];

            self::assertContains($role . '|establishment|' . $establishment, $this->rolesOf($tenant, $person), $app);
            $this->activate(sprintf('+2376994200%02d', $i + 1), 'Recette-Solide-' . substr(md5($app), 0, 8) . '!');
            self::assertTrue($checker->can(new Principal($user, $tenant), $permission, $establishment), $app);
        }
    }

    private function assignmentCount(): int
    {
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM role_assignments');
        $count->execute();

        return (int) $count->fetchColumn();
    }

    private function pilotSetup(string $tenant, string $template): void
    {
        $command = $this->kernel->container()->get('console.command.' . PilotSetupCommand::NAME);
        self::assertInstanceOf(PilotSetupCommand::class, $command);
        ob_start();
        $code = $command->run([$tenant, $template]);
        $output = (string) ob_get_clean();
        self::assertSame(0, $code, $output);
    }

    private function grantCommand(): string
    {
        $command = $this->kernel->container()->get('console.command.' . GrantApplicationRolesCommand::NAME);
        self::assertInstanceOf(GrantApplicationRolesCommand::class, $command);
        ob_start();
        $code = $command->run([]);
        $output = (string) ob_get_clean();
        self::assertSame(0, $code, $output);

        return $output;
    }

    /** @return list<string> rôle|périmètre|identifiant, triés */
    private function rolesOf(string $tenant, string $person): array
    {
        $rows = $this->pdo()->prepare('SELECT role, scope_type, scope_id FROM role_assignments WHERE tenant_id = ? AND person_id = ?');
        $rows->execute([$tenant, $person]);
        $list = array_map(static fn (array $r): string => $r['role'] . '|' . $r['scope_type'] . '|' . $r['scope_id'], $rows->fetchAll(\PDO::FETCH_ASSOC));
        sort($list);

        return $list;
    }

    /**
     * Retire des affectations pour simuler un client accepté avant la correction.
     *
     * @param list<string> $roles
     */
    private function forget(string $tenant, string $person, array $roles): void
    {
        $delete = $this->pdo()->prepare('DELETE FROM role_assignments WHERE tenant_id = ? AND person_id = ? AND role = ?');
        foreach ($roles as $role) {
            $delete->execute([$tenant, $person, $role]);
        }
    }
}
