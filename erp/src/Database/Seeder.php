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
     * Referentiel de la plateforme : permissions, roles, Super Admin.
     *
     * Le Super Admin n'appartient a aucun etablissement — il administre la
     * plateforme et arbitre les demandes de creation. Lui adjoindre une ecole
     * de demonstration n'aurait pas de sens : il n'en dirige aucune. Le jeu de
     * demonstration ne s'installe donc que sur demande explicite, via
     * "artisan demo".
     *
     * @return list<string> lignes de compte rendu
     */
    public function run(
        string $superAdminEmail,
        string $superAdminPassword,
        string $tenantCode = 'DEMO',
        string $tenantName = 'Etablissement Demo',
        bool $withDemoTenant = false
    ): array {
        $report = [];

        $permissionIds = $this->seedPermissions();
        $report[] = count($permissionIds).' permissions';

        $roleCount = $this->seedRoles($permissionIds);
        $report[] = $roleCount.' roles';

        $this->seedSuperAdmin($superAdminEmail, $superAdminPassword);
        $report[] = 'super admin '.$superAdminEmail.' (hors etablissement)';

        $templateCount = $this->seedSystemTemplates();
        $report[] = $templateCount.' modeles de communication systeme';

        if (! $withDemoTenant) {
            return $report;
        }

        $tenantId = $this->seedTenant($tenantCode, $tenantName);
        $this->tenant->set($tenantId);
        $report[] = 'etablissement '.$tenantCode;

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

    /**
     * Modeles systeme (tenant_id NULL), repli commun a tous les
     * etablissements. Charges une seule fois : un Super Admin peut ensuite
     * les personnaliser depuis /communication sans qu'un re-seed les ecrase.
     */
    private function seedSystemTemplates(): int
    {
        $templates = [
            [
                'code' => 'account.created',
                'name' => 'Compte cree',
                'channel' => 'EMAIL',
                'subject_fr' => 'Votre acces {{appName}}',
                'body_fr' => "Bonjour {{name}},\n\nUn acces vous a ete ouvert sur {{appName}}.\n\n".
                    "Adresse : {{loginUrl}}\nIdentifiant : {{email}}\nMot de passe provisoire : {{password}}\n\n".
                    "Changez ce mot de passe des votre premiere connexion.\n\n{{appName}}",
            ],
            [
                'code' => 'billing.overdue',
                'name' => 'Relance impaye',
                'channel' => 'EMAIL',
                'subject_fr' => 'Facture en retard - {{studentName}}',
                'body_fr' => "Bonjour {{parentName}},\n\nLa scolarite de {{studentName}} presente un solde impaye ".
                    "depuis le {{dueDate}}, pour un montant de {{amountDue}}.\n\n".
                    "Merci de regulariser aupres de l etablissement des que possible.\n\n{{appName}}",
            ],
            [
                'code' => 'attendance.absence',
                'name' => 'Absence signalee',
                'channel' => 'EMAIL',
                'subject_fr' => 'Absence signalee - {{studentName}}',
                'body_fr' => "Bonjour {{parentName}},\n\n{{studentName}} a ete signale(e) \"{{status}}\" le {{date}}.\n\n".
                    "Contactez l etablissement si cette absence n est pas justifiee.\n\n{{appName}}",
            ],
            [
                'code' => 'discipline.incident',
                'name' => 'Incident disciplinaire',
                'channel' => 'EMAIL',
                'subject_fr' => 'Incident disciplinaire - {{studentName}}',
                'body_fr' => "Bonjour {{parentName}},\n\nUn incident disciplinaire concernant {{studentName}} a ete ".
                    "enregistre le {{date}} ({{type}}).\n\nL etablissement reste a votre disposition pour en discuter.\n\n{{appName}}",
            ],
            [
                'code' => 'tenant.suspended',
                'name' => 'Suspension etablissement',
                'channel' => 'EMAIL',
                'subject_fr' => 'Etablissement suspendu - {{establishmentName}}',
                'body_fr' => "Bonjour {{directorName}},\n\n{{establishmentName}} a ete suspendu sur {{appName}}.\n\n".
                    "Motif : {{reason}}\n\nAucun compte de l etablissement ne peut plus se connecter tant que la ".
                    "suspension n est pas levee. Contactez l administration de la plateforme pour la regulariser.\n\n{{appName}}",
            ],
            [
                'code' => 'tenant.reactivated',
                'name' => 'Reactivation etablissement',
                'channel' => 'EMAIL',
                'subject_fr' => 'Etablissement reactive - {{establishmentName}}',
                'body_fr' => "Bonjour {{directorName}},\n\n{{establishmentName}} est de nouveau actif sur {{appName}}.\n\n".
                    "Les comptes de l etablissement peuvent de nouveau se connecter.\n\n{{appName}}",
            ],
        ];

        $count = 0;

        foreach ($templates as $template) {
            $exists = $this->db->scalar(
                'SELECT id FROM communication_templates WHERE tenant_id IS NULL AND code = :code',
                ['code' => $template['code']]
            );

            if ($exists !== null) {
                continue;
            }

            $now = $this->now();

            $this->db->execute(
                'INSERT INTO communication_templates
                    (id, tenant_id, code, name, channel, subject_fr, body_fr, created_at, updated_at)
                 VALUES (:id, NULL, :code, :name, :channel, :subject_fr, :body_fr, :created_at, :updated_at)',
                [
                    'id' => Table::uuid(),
                    'code' => $template['code'],
                    'name' => $template['name'],
                    'channel' => $template['channel'],
                    'subject_fr' => $template['subject_fr'],
                    'body_fr' => $template['body_fr'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $count++;
        }

        return $count;
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

    /**
     * Administrateur de la plateforme.
     *
     * Volontairement rattache a aucun etablissement (tenant_id nul) : il
     * administre la plateforme entiere. A ne pas confondre avec
     * l'administrateur d'une ecole, souvent le directeur, qui lui appartient a
     * son etablissement et n'en voit aucun autre.
     */
    private function seedSuperAdmin(string $email, string $password): void
    {
        $this->detachSuperAdminFromAnyTenant($email);

        $userId = $this->createUser(null, $email, $password, 'Super', 'Admin');
        $this->assignRole($userId, 'SUPER_ADMIN');
    }

    /**
     * Repare une installation ou le Super Admin s'est retrouve rattache a une
     * ecole.
     *
     * Un compte de plateforme rattache a un etablissement en affiche le nom
     * partout, et surtout se retrouve soumis au filtrage par etablissement :
     * il cesse alors de voir les demandes des autres, ce qui est precisement
     * son travail. Le detacher est sans effet quand tout est deja en ordre.
     */
    private function detachSuperAdminFromAnyTenant(string $email): void
    {
        $attached = $this->db->scalar(
            'SELECT u.id FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.email = :email AND r.name = :role AND u.tenant_id IS NOT NULL',
            ['email' => $email, 'role' => 'SUPER_ADMIN']
        );

        if ($attached === null) {
            return;
        }

        $this->db->execute(
            'UPDATE users SET tenant_id = NULL, updated_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $attached]
        );
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
            'comptable@demo.scholaris.cm' => ['Comptable', 'Colette', 'Comptable'],
            'surveillant-general@demo.scholaris.cm' => ['Surveillant général', 'Serge', 'Surveillant'],
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

    /**
     * @param  string|null  $tenantId  null pour un compte de plateforme
     */
    private function createUser(
        ?string $tenantId,
        string $email,
        string $password,
        string $firstName,
        string $lastName
    ): string {
        // "tenant_id = NULL" ne correspond a rien en SQL : la recherche d'un
        // compte de plateforme doit passer par IS NULL.
        $existing = $tenantId === null
            ? $this->db->scalar(
                'SELECT id FROM users WHERE tenant_id IS NULL AND email = :email',
                ['email' => $email]
            )
            : $this->db->scalar(
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
