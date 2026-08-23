<?php

declare(strict_types=1);

namespace Scholaris\Database;

use RuntimeException;
use Scholaris\Auth\Auth;
use Scholaris\Tenant\TenantContext;

/**
 * Creation d'un etablissement et de son compte responsable.
 *
 * Meme chemin que l'approbation d'une demande publique : etablissement, compte
 * du chef d'etablissement, role d'administrateur et structure pedagogique
 * conforme au type. Le tout en une transaction — un etablissement sans
 * administrateur, ou un compte rattache a rien, serait inexploitable.
 *
 * La structure posee depend du type : une ecole primaire recoit SIL a CM2, un
 * college la 6eme a la 3eme. Creer une ecole primaire avec des classes de
 * terminale n'aurait aucun sens.
 */
final class SchoolFactory
{
    /** Structure pedagogique par defaut, par type d'etablissement. */
    private const STRUCTURES = [
        'PRIMAIRE' => [
            ['PRIMAIRE', 'Primaire', [
                ['SIL', "SIL (Section d'Initiation au Langage)"],
                ['CP', 'CP (Cours Preparatoire)'],
                ['CE1', 'CE1 (Cours Elementaire 1)'],
                ['CE2', 'CE2 (Cours Elementaire 2)'],
                ['CM1', 'CM1 (Cours Moyen 1)'],
                ['CM2', 'CM2 (Cours Moyen 2)'],
            ]],
        ],
        'COLLEGE' => [
            ['COLLEGE', 'Secondaire 1er cycle', [
                ['6EME', '6eme'], ['5EME', '5eme'], ['4EME', '4eme'], ['3EME', '3eme'],
            ]],
        ],
        'LYCEE_GENERAL' => [
            ['COLLEGE', 'Secondaire 1er cycle', [
                ['6EME', '6eme'], ['5EME', '5eme'], ['4EME', '4eme'], ['3EME', '3eme'],
            ]],
            ['LYCEE', 'Secondaire 2nd cycle', [
                ['2NDE', '2nde'], ['1ERE', '1ere'], ['TLE', 'Terminale'],
            ]],
        ],
        'LYCEE_TECHNIQUE' => [
            ['COLLEGE', 'Premier cycle technique', [
                ['1AN', '1ere annee'], ['2AN', '2eme annee'], ['3AN', '3eme annee'], ['4AN', '4eme annee'],
            ]],
            ['LYCEE', 'Second cycle technique', [
                ['2NDE', '2nde'], ['1ERE', '1ere'], ['TLE', 'Terminale'],
            ]],
        ],
        'CENTRE_FORMATION' => [
            ['FORMATION', 'Formation professionnelle', [
                ['AN1', 'Annee 1'], ['AN2', 'Annee 2'], ['AN3', 'Annee 3'],
            ]],
        ],
        'SUPERIEUR' => [
            ['LICENCE', 'Licence', [['L1', 'Licence 1'], ['L2', 'Licence 2'], ['L3', 'Licence 3']]],
            ['MASTER', 'Master', [['M1', 'Master 1'], ['M2', 'Master 2']]],
        ],
    ];

    private Connection $db;

    private TenantContext $tenant;

    public function __construct(Connection $db, TenantContext $tenant)
    {
        $this->db = $db;
        $this->tenant = $tenant;
    }

    /**
     * @return array{tenant_id: string, password: string, levels: int}
     */
    public function create(
        string $code,
        string $name,
        string $type,
        string $directorEmail,
        string $directorFirstName,
        string $directorLastName,
        ?string $password = null
    ): array {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        if ($code === '') {
            throw new RuntimeException('Le code doit contenir des lettres ou des chiffres.');
        }

        if (! isset(self::STRUCTURES[$type])) {
            throw new RuntimeException(
                'Type inconnu : '.$type.'. Valeurs admises : '.implode(', ', array_keys(self::STRUCTURES)).'.'
            );
        }

        if ($this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => $code]) !== null) {
            throw new RuntimeException('Le code '.$code.' est deja utilise par un autre etablissement.');
        }

        $password ??= $this->generatePassword();

        return $this->db->transaction(function () use (
            $code, $name, $type, $directorEmail, $directorFirstName, $directorLastName, $password
        ): array {
            $now = date('Y-m-d H:i:s');
            $tenantId = Table::uuid();

            $this->db->execute(
                'INSERT INTO tenants (id, code, name, type, status, public_enrollment_enabled, created_at, updated_at)
                 VALUES (:id, :code, :name, :type, :status, :public_enrollment, :created_at, :updated_at)',
                [
                    'id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'status' => 'PUBLIC',
                    // La pre-inscription en ligne est ouverte d'emblee : c'est
                    // ce que la plupart des etablissements attendent en
                    // s'inscrivant sur la plateforme.
                    'public_enrollment' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $this->tenant->set($tenantId);

            $userId = Table::uuid();

            $this->db->execute(
                'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status, created_at, updated_at)
                 VALUES (:id, :tenant, :email, :hash, :first, :last, :status, :created_at, :updated_at)',
                [
                    'id' => $userId,
                    'tenant' => $tenantId,
                    'email' => $directorEmail,
                    'hash' => Auth::hash($password),
                    'first' => $directorFirstName,
                    'last' => $directorLastName,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // Le chef d'etablissement recoit les droits d'administration de son
            // ecole, et d'elle seule.
            $roleId = $this->db->scalar(
                'SELECT id FROM roles WHERE tenant_id IS NULL AND name = :name',
                ['name' => 'Admin Établissement']
            );

            if ($roleId === null) {
                throw new RuntimeException('Referentiel des roles absent : lancez "artisan seed" avant.');
            }

            $this->db->execute(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
                ['user' => $userId, 'role' => $roleId]
            );

            $this->db->execute(
                'INSERT INTO employees (id, tenant_id, user_id, first_name, last_name, position, hire_date, status, created_at, updated_at)
                 VALUES (:id, :tenant, :user, :first, :last, :position, :hire_date, :status, :created_at, :updated_at)',
                [
                    'id' => Table::uuid(),
                    'tenant' => $tenantId,
                    'user' => $userId,
                    'first' => $directorFirstName,
                    'last' => $directorLastName,
                    'position' => 'Directeur',
                    'hire_date' => date('Y-m-d'),
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $academicYearId = $this->createAcademicYear($tenantId, $now);
            $levels = $this->createStructure($tenantId, $type, $now);

            return ['tenant_id' => $tenantId, 'password' => $password, 'levels' => $levels];
        });
    }

    private function createAcademicYear(string $tenantId, string $now): string
    {
        $start = new \DateTimeImmutable(date('Y').'-09-01');
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'label' => $start->format('Y').'-'.$start->modify('+1 year')->format('Y'),
                'start' => $start->format('Y-m-d'),
                'end' => $start->modify('+10 months')->format('Y-m-d'),
                'status' => 'ACTIVE',
                'created_at' => $now,
            ]
        );

        // Six sequences, decoupage standard du systeme camerounais. La premiere
        // est ouverte : sans periode ouverte, aucune note ne peut etre saisie.
        for ($number = 1; $number <= 6; $number++) {
            $this->db->execute(
                'INSERT INTO periods (id, academic_year_id, type, number, start_date, end_date, grading_status)
                 VALUES (:id, :year, :type, :number, :start, :end, :status)',
                [
                    'id' => Table::uuid(),
                    'year' => $id,
                    'type' => 'SEQUENCE',
                    'number' => $number,
                    'start' => $start->modify('+'.(($number - 1) * 6).' weeks')->format('Y-m-d'),
                    'end' => $start->modify('+'.($number * 6).' weeks')->format('Y-m-d'),
                    'status' => $number === 1 ? 'OPEN' : 'CLOSED',
                ]
            );
        }

        return $id;
    }

    private function createStructure(string $tenantId, string $type, string $now): int
    {
        $levels = 0;

        foreach (self::STRUCTURES[$type] as $cycleOrder => [$code, $name, $cycleLevels]) {
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
                    'created_at' => $now,
                ]
            );

            foreach ($cycleLevels as $levelOrder => [$levelCode, $levelName]) {
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
                        'created_at' => $now,
                    ]
                );

                $levels++;
            }
        }

        return $levels;
    }

    /**
     * Mot de passe initial : assez long pour ne pas etre devine, sans
     * caracteres ambigus, car il sera dicte au telephone.
     */
    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
