<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Cycle;
use App\Models\Level;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Etablissement de demonstration : structure pedagogique camerounaise standard
 * (primaire, college, lycee), annee academique ouverte, et un compte par role
 * metier pour tester les habilitations.
 *
 * Idempotent : rejouable sans creer de doublon.
 */
class DemoTenantSeeder extends Seeder
{
    /** Mot de passe commun aux comptes de demonstration. */
    private const DEMO_PASSWORD = 'Test123!';

    public function run(): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['code' => env('SEED_TENANT_CODE', 'DEMO')],
            [
                'name' => env('SEED_TENANT_NAME', 'Etablissement Demo'),
                'type' => 'SECONDAIRE',
                'status' => 'PRIVE',
                'email' => 'contact@demo.scholaris.cm',
            ],
        );

        // Le seeder tourne hors requete HTTP : aucun middleware n'a renseigne
        // l'etablissement courant, on le pose donc explicitement pour que les
        // modeles scopes ecrivent le bon tenant_id.
        app(TenantContext::class)->set($tenant->id);

        $this->seedSuperAdmin($tenant);
        $academicYear = $this->seedAcademicYear($tenant);
        $this->seedStructure($tenant);
        $this->seedBusinessUsers($tenant);

        $this->command?->info("Etablissement {$tenant->code} pret (annee {$academicYear->label}).");
    }

    private function seedSuperAdmin(Tenant $tenant): void
    {
        $user = User::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => env('SEED_SUPER_ADMIN_EMAIL', 'admin@scholaris.dev')],
            [
                'password_hash' => Hash::make(env('SEED_SUPER_ADMIN_PASSWORD', 'ChangeMe123!')),
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'status' => 'ACTIVE',
            ],
        );

        $this->assignRole($user, 'SUPER_ADMIN');
    }

    private function seedAcademicYear(Tenant $tenant): AcademicYear
    {
        $start = now()->startOfYear()->addMonths(8);
        $label = $start->year.'-'.($start->year + 1);

        $year = AcademicYear::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'label' => $label],
            [
                'start_date' => $start,
                'end_date' => $start->copy()->addMonths(10),
                'status' => 'ACTIVE',
            ],
        );

        // Six sequences, regroupees deux par deux en trimestres : decoupage
        // standard du secondaire camerounais.
        for ($number = 1; $number <= 6; $number++) {
            $year->periods()->updateOrCreate(
                ['type' => 'SEQUENCE', 'number' => $number],
                [
                    'start_date' => $start->copy()->addWeeks(($number - 1) * 6),
                    'end_date' => $start->copy()->addWeeks($number * 6),
                    'grading_status' => $number === 1 ? 'OPEN' : 'CLOSED',
                ],
            );
        }

        return $year;
    }

    private function seedStructure(Tenant $tenant): void
    {
        foreach ($this->cycleDefinitions() as $order => $definition) {
            $cycle = Cycle::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $definition['code']],
                ['name' => $definition['name'], 'order' => $order + 1],
            );

            foreach ($definition['levels'] as $levelOrder => $level) {
                Level::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $level['code']],
                    ['name' => $level['name'], 'order' => $levelOrder + 1, 'cycle_id' => $cycle->id],
                );
            }
        }
    }

    /**
     * Un compte par role metier, pour valider les habilitations bout en bout.
     */
    private function seedBusinessUsers(Tenant $tenant): void
    {
        foreach ($this->demoAccounts() as $email => [$roleName, $firstName, $lastName]) {
            $user = User::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'email' => $email],
                [
                    'password_hash' => Hash::make(self::DEMO_PASSWORD),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'status' => 'ACTIVE',
                ],
            );

            $this->assignRole($user, $roleName);
        }
    }

    private function assignRole(User $user, string $roleName): void
    {
        $role = Role::query()->whereNull('tenant_id')->where('name', $roleName)->first();

        if ($role !== null) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    /**
     * @return array<int, array{code: string, name: string, levels: array<int, array{code: string, name: string}>}>
     */
    private function cycleDefinitions(): array
    {
        return [
            [
                'code' => 'PRIMAIRE',
                'name' => 'Primaire',
                'levels' => [
                    ['code' => 'SIL', 'name' => "SIL (Section d'Initiation au Langage)"],
                    ['code' => 'CP', 'name' => 'CP (Cours Preparatoire)'],
                    ['code' => 'CE1', 'name' => 'CE1 (Cours Elementaire 1)'],
                    ['code' => 'CE2', 'name' => 'CE2 (Cours Elementaire 2)'],
                    ['code' => 'CM1', 'name' => 'CM1 (Cours Moyen 1)'],
                    ['code' => 'CM2', 'name' => 'CM2 (Cours Moyen 2)'],
                ],
            ],
            [
                'code' => 'COLLEGE',
                'name' => 'Secondaire 1er cycle (College)',
                'levels' => [
                    ['code' => '6EME', 'name' => '6eme'],
                    ['code' => '5EME', 'name' => '5eme'],
                    ['code' => '4EME', 'name' => '4eme'],
                    ['code' => '3EME', 'name' => '3eme'],
                ],
            ],
            [
                'code' => 'LYCEE',
                'name' => 'Secondaire 2nd cycle (Lycee)',
                'levels' => [
                    ['code' => '2NDE', 'name' => '2nde'],
                    ['code' => '1ERE', 'name' => '1ere'],
                    ['code' => 'TLE', 'name' => 'Terminale'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function demoAccounts(): array
    {
        return [
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
    }
}
