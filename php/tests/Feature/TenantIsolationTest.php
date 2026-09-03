<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * L'isolation entre etablissements est la garantie de securite centrale de
 * l'application : ces tests verifient qu'elle tient au niveau du modele, et pas
 * seulement dans les controleurs.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithStudent(string $code, string $matricule): Tenant
    {
        $tenant = Tenant::create([
            'code' => $code,
            'name' => "Etablissement {$code}",
            'type' => 'SECONDAIRE',
        ]);

        Student::create([
            'tenant_id' => $tenant->id,
            'matricule' => $matricule,
            'first_name' => 'Eleve',
            'last_name' => $code,
            'date_of_birth' => '2010-01-01',
            'gender' => 'MALE',
        ]);

        return $tenant;
    }

    public function test_le_scope_global_ne_renvoie_que_les_eleves_de_l_etablissement_courant(): void
    {
        $a = $this->makeTenantWithStudent('A', 'A/001');
        $this->makeTenantWithStudent('B', 'B/001');

        app(TenantContext::class)->set($a->id);

        $students = Student::all();

        $this->assertCount(1, $students);
        $this->assertSame('A/001', $students->first()->matricule);
    }

    public function test_un_eleve_d_un_autre_etablissement_est_introuvable_par_son_id(): void
    {
        $a = $this->makeTenantWithStudent('A', 'A/001');
        $b = $this->makeTenantWithStudent('B', 'B/001');

        app(TenantContext::class)->set($b->id);
        $foreignStudentId = Student::first()->id;

        app(TenantContext::class)->set($a->id);

        // Anti-IDOR : connaitre l'identifiant ne suffit pas a lire la ligne.
        $this->assertNull(Student::find($foreignStudentId));
    }

    public function test_le_tenant_id_est_renseigne_automatiquement_a_la_creation(): void
    {
        $tenant = Tenant::create(['code' => 'C', 'name' => 'C', 'type' => 'SECONDAIRE']);
        app(TenantContext::class)->set($tenant->id);

        $student = Student::create([
            'matricule' => 'C/001',
            'first_name' => 'Auto',
            'last_name' => 'Scope',
            'date_of_birth' => '2011-05-05',
            'gender' => 'FEMALE',
        ]);

        $this->assertSame($tenant->id, $student->tenant_id);
    }

    public function test_le_mode_global_leve_le_scope_puis_le_retablit(): void
    {
        $a = $this->makeTenantWithStudent('A', 'A/001');
        $this->makeTenantWithStudent('B', 'B/001');

        $context = app(TenantContext::class);
        $context->set($a->id);

        $all = $context->global(fn () => Student::count());

        $this->assertSame(2, $all);
        $this->assertSame(1, Student::count(), 'Le scope doit etre retabli apres le mode global.');
    }

    public function test_le_tableau_de_bord_ne_compte_que_les_eleves_de_l_utilisateur(): void
    {
        $a = $this->makeTenantWithStudent('A', 'A/001');
        $this->makeTenantWithStudent('B', 'B/001');
        $this->makeTenantWithStudent('B2', 'B2/001');

        $user = User::create([
            'tenant_id' => $a->id,
            'email' => 'chef@a.cm',
            'password_hash' => Hash::make('Test123!'),
            'first_name' => 'Chef',
            'last_name' => 'A',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Eleves actifs');

        $this->assertSame(1, $user->tenant->students()->count());
    }
}
