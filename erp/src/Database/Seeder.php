<?php

declare(strict_types=1);

namespace Scholaris\Database;

use Scholaris\Auth\Auth;
use Scholaris\Tenant\TenantContext;

/**
 * Donnees initiales : referentiel RBAC, puis etablissement de demonstration.
 *
 * Idempotent : chaque enregistrement est cree seulement s'il n'existe pas, de
 * sorte que le seeder puisse etre rejoue apres l'ajout d'une permission sans
 * dupliquer quoi que ce soit ni ecraser des donnees reelles.
 */
final class Seeder
{
    private const DEMO_PASSWORD = 'Test123!';

    private Connection $db;

    private TenantContext $tenant;

    private string $basePath;

    public function __construct(Connection $db, TenantContext $tenant, string $basePath)
    {
        $this->db = $db;
        $this->tenant = $tenant;
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * @return list<string> lignes de compte rendu
     */
    public function run(string $superAdminEmail, string $superAdminPassword, string $tenantCode, string $tenantName): array
    {
        $report = [];

        $permissionIds = $this->seedPermissions();
        $report[] = count($permissionIds).' permissions';

        $roleCount = $this->seedRoles($permissionIds);
        $report[] = $roleCount.' roles';

        $tenantId = $this->seedTenant($tenantCode, $tenantName);
        $this->tenant->set($tenantId);
        $report[] = 'etablissement '.$tenantCode;

        $this->seedSuperAdmin($tenantId, $superAdminEmail, $superAdminPassword);
        $accounts = $this->seedBusinessUsers($tenantId);
        $report[] = $accounts.' comptes de demonstration';

        $yearLabel = $this->seedAcademicYear($tenantId);
        $report[] = 'annee academique '.$yearLabel;

        $levels = $this->seedStructure($tenantId);
        $report[] = $levels.' niveaux dans 3 cycles';

        return $report;
    }

    /**
     * @return array<string, string> "resource:action" vers identifiant
     */
    private function seedPermissions(): array
    {
        /** @var array{permissions: list<array{0: string, 1: string, 2: string}>, roles: list<array{name: string, description: string, permissions: list<string>}>} $matrix */
        $matrix = require $this->basePath.'/database/rbac-matrix.php';

        foreach ($matrix['permissions'] as [$resource, $action, $description]) {
            $exists = $this->db->scalar(
                'SELECT id FROM permissions WHERE resource = :r AND action = :a',
                ['r' => $resource, 'a' => $action]
            );

            if ($exists === null) {
                $this->db->execute(
                    'INSERT INTO permissions (id, resource, action, description) VALUES (:id, :r, :a, :d)',
                    ['id' => Table::uuid(), 'r' => $resource, 'a' => $action, 'd' => $description]
                );
            }
        }

        $map = [];

        foreach ($this->db->select('SELECT id, resource, action FROM permissions') as $row) {
            $map[$row['resource'].':'.$row['action']] = (string) $row['id'];
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $permissionIds
     */
    private function seedRoles(array $permissionIds): int
    {
        $matrix = require $this->basePath.'/database/rbac-matrix.php';

        // Le Super Admin porte toutes les permissions et n'appartient a aucun
        // etablissement : il administre la plateforme entiere.
        $this->syncRole('SUPER_ADMIN', 'Administrateur de la plateforme', array_values($permissionIds));

        $count = 1;

        foreach ($matrix['roles'] as $role) {
            $ids = [];

            foreach ($role['permissions'] as $key) {
                if (isset($permissionIds[$key])) {
                    $ids[] = $permissionIds[$key];
                }
            }

            $this->syncRole($role['name'], $role['description'], $ids);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<string>  $permissionIds
     */
    private function syncRole(string $name, string $description, array $permissionIds): void
    {
        $roleId = $this->db->scalar(
            'SELECT id FROM roles WHERE tenant_id IS NULL AND name = :name',
            ['name' => $name]
        );

        if ($roleId === null) {
            $roleId = Table::uuid();
            $this->db->execute(
                'INSERT INTO roles (id, tenant_id, name, description, is_system, created_at)
                 VALUES (:id, NULL, :name, :description, 1, :now)',
                ['id' => $roleId, 'name' => $name, 'description' => $description, 'now' => $this->now()]
            );
        }

        // Les permissions du role sont reecrites entierement : une permission
        // retiree de la matrice doit disparaitre, pas subsister en base.
        $this->db->execute('DELETE FROM role_permissions WHERE role_id = :role', ['role' => $roleId]);

        foreach ($permissionIds as $permissionId) {
            $this->db->execute(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)',
                ['role' => $roleId, 'permission' => $permissionId]
            );
        }
    }

    private function seedTenant(string $code, string $name): string
    {
        $existing = $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => $code]);

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO tenants (id, code, name, type, status, email, created_at, updated_at)
             VALUES (:id, :code, :name, :type, :status, :email, :created_at, :updated_at)',
            [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'type' => 'SECONDAIRE',
                'status' => 'PRIVE',
                'email' => 'contact@demo.scholaris.cm',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        return $id;
    }

    private function seedSuperAdmin(string $tenantId, string $email, string $password): void
    {
        $userId = $this->createUser($tenantId, $email, $password, 'Super', 'Admin');
        $this->assignRole($userId, 'SUPER_ADMIN');
    }

    private function seedBusinessUsers(string $tenantId): int
    {
        $accounts = [
            'admin-etablissement@demo.scholaris.cm' => ['Admin Établissement', 'Alain', 'Etablissement'],
            'directeur@demo.scholaris.cm' => ['Directeur', 'Daniel', 'Directeur'],
            'censeur@demo.scholaris.cm' => ['Censeur', 'Clarisse', 'Censeur'],
            'chef-departement@demo.scholaris.cm' => ['Chef de département', 'Charles', 'Departement'],
            'enseignant@demo.scholaris.cm' => ['Enseignant', 'Estelle', 'Enseignant'],
            'intendant@demo.scholaris.cm' => ['Intendant', 'Ibrahim', 'Intendant'],
            'secretaire@demo.scholaris.cm' => ['Secrétaire', 'Sandrine', 'Secretaire'],
            'infirmier@demo.scholaris.cm' => ['Infirmier(ère)', 'Ines', 'Infirmier'],
            'bibliothecaire@demo.scholaris.cm' => ['Bibliothécaire', 'Bertrand', 'Bibliothecaire'],
            'parent@demo.scholaris.cm' => ['Parent', 'Paul', 'Parent'],
            'eleve@demo.scholaris.cm' => ['Élève', 'Eric', 'Eleve'],
        ];

        foreach ($accounts as $email => [$role, $firstName, $lastName]) {
            $userId = $this->createUser($tenantId, $email, self::DEMO_PASSWORD, $firstName, $lastName);
            $this->assignRole($userId, $role);
        }

        return count($accounts);
    }

    private function createUser(
        string $tenantId,
        string $email,
        string $password,
        string $firstName,
        string $lastName
    ): string {
        $existing = $this->db->scalar(
            'SELECT id FROM users WHERE tenant_id = :tenant AND email = :email',
            ['tenant' => $tenantId, 'email' => $email]
        );

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status, created_at, updated_at)
             VALUES (:id, :tenant, :email, :hash, :first, :last, :status, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'email' => $email,
                'hash' => Auth::hash($password),
                'first' => $firstName,
                'last' => $lastName,
                'status' => 'ACTIVE',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        return $id;
    }

    private function assignRole(string $userId, string $roleName): void
    {
        $roleId = $this->db->scalar(
            'SELECT id FROM roles WHERE tenant_id IS NULL AND name = :name',
            ['name' => $roleName]
        );

        if ($roleId === null) {
            return;
        }

        $exists = $this->db->scalar(
            'SELECT 1 FROM user_roles WHERE user_id = :user AND role_id = :role',
            ['user' => $userId, 'role' => $roleId]
        );

        if ($exists === null) {
            $this->db->execute(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
                ['user' => $userId, 'role' => $roleId]
            );
        }
    }

    private function seedAcademicYear(string $tenantId): string
    {
        $start = new \DateTimeImmutable(date('Y').'-09-01');
        $label = $start->format('Y').'-'.($start->modify('+1 year')->format('Y'));

        $existing = $this->db->scalar(
            'SELECT id FROM academic_years WHERE tenant_id = :tenant AND label = :label',
            ['tenant' => $tenantId, 'label' => $label]
        );

        if ($existing !== null) {
            return $label;
        }

        $yearId = Table::uuid();

        $this->db->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $yearId,
                'tenant' => $tenantId,
                'label' => $label,
                'start' => $start->format('Y-m-d'),
                'end' => $start->modify('+10 months')->format('Y-m-d'),
                'status' => 'ACTIVE',
                'created_at' => $this->now(),
            ]
        );

        // Six sequences, groupees deux par deux en trimestres : decoupage
        // standard du secondaire camerounais.
        for ($number = 1; $number <= 6; $number++) {
            $this->db->execute(
                'INSERT INTO periods (id, academic_year_id, type, number, start_date, end_date, grading_status)
                 VALUES (:id, :year, :type, :number, :start, :end, :status)',
                [
                    'id' => Table::uuid(),
                    'year' => $yearId,
                    'type' => 'SEQUENCE',
                    'number' => $number,
                    'start' => $start->modify('+'.(($number - 1) * 6).' weeks')->format('Y-m-d'),
                    'end' => $start->modify('+'.($number * 6).' weeks')->format('Y-m-d'),
                    'status' => $number === 1 ? 'OPEN' : 'CLOSED',
                ]
            );
        }

        return $label;
    }

    private function seedStructure(string $tenantId): int
    {
        $cycles = [
            ['PRIMAIRE', 'Primaire', [
                ['SIL', 'SIL'], ['CP', 'CP'], ['CE1', 'CE1'],
                ['CE2', 'CE2'], ['CM1', 'CM1'], ['CM2', 'CM2'],
            ]],
            ['COLLEGE', 'Secondaire 1er cycle (College)', [
                ['6EME', '6eme'], ['5EME', '5eme'], ['4EME', '4eme'], ['3EME', '3eme'],
            ]],
            ['LYCEE', 'Secondaire 2nd cycle (Lycee)', [
                ['2NDE', '2nde'], ['1ERE', '1ere'], ['TLE', 'Terminale'],
            ]],
        ];

        $levelCount = 0;

        foreach ($cycles as $cycleOrder => [$code, $name, $levels]) {
            $cycleId = $this->db->scalar(
                'SELECT id FROM cycles WHERE tenant_id = :tenant AND code = :code',
                ['tenant' => $tenantId, 'code' => $code]
            );

            if ($cycleId === null) {
                $cycleId = Table::uuid();
                $this->db->execute(
                    'INSERT INTO cycles (id, tenant_id, code, name, sort_order, created_at)
                     VALUES (:id, :tenant, :code, :name, :sort, :created_at)',
                    [
                        'id' => $cycleId,
                        'tenant' => $tenantId,
                        'code' => $code,
                        'name' => $name,
                        'sort' => $cycleOrder + 1,
                        'created_at' => $this->now(),
                    ]
                );
            }

            foreach ($levels as $levelOrder => [$levelCode, $levelName]) {
                $levelCount++;

                $exists = $this->db->scalar(
                    'SELECT id FROM levels WHERE tenant_id = :tenant AND code = :code',
                    ['tenant' => $tenantId, 'code' => $levelCode]
                );

                if ($exists !== null) {
                    continue;
                }

                $this->db->execute(
                    'INSERT INTO levels (id, tenant_id, code, name, sort_order, cycle_id, created_at)
                     VALUES (:id, :tenant, :code, :name, :sort, :cycle, :created_at)',
                    [
                        'id' => Table::uuid(),
                        'tenant' => $tenantId,
                        'code' => $levelCode,
                        'name' => $levelName,
                        'sort' => $levelOrder + 1,
                        'cycle' => $cycleId,
                        'created_at' => $this->now(),
                    ]
                );
            }
        }

        return $levelCount;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
