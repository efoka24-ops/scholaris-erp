<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $tenantCode, string $email): User
    {
        $tenant = Tenant::create([
            'code' => $tenantCode,
            'name' => "Etablissement {$tenantCode}",
            'type' => 'SECONDAIRE',
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'password_hash' => Hash::make('Test123!'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
    }

    public function test_un_utilisateur_valide_accede_au_tableau_de_bord(): void
    {
        $user = $this->makeUser('A', 'directeur@a.cm');

        $response = $this->post('/login', [
            'email' => 'directeur@a.cm',
            'password' => 'Test123!',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login);
    }

    public function test_un_mot_de_passe_errone_est_refuse(): void
    {
        $this->makeUser('A', 'directeur@a.cm');

        $response = $this->post('/login', [
            'email' => 'directeur@a.cm',
            'password' => 'mauvais',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_le_compte_se_verrouille_apres_cinq_echecs(): void
    {
        $user = $this->makeUser('A', 'directeur@a.cm');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'directeur@a.cm', 'password' => 'mauvais']);
        }

        $user->refresh();
        $this->assertSame(5, $user->failed_login_attempts);
        $this->assertTrue($user->isLocked());

        // Meme avec le bon mot de passe, la connexion reste bloquee.
        $this->post('/login', ['email' => 'directeur@a.cm', 'password' => 'Test123!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_une_connexion_reussie_remet_le_compteur_a_zero(): void
    {
        $user = $this->makeUser('A', 'directeur@a.cm');
        $this->post('/login', ['email' => 'directeur@a.cm', 'password' => 'mauvais']);
        $this->assertSame(1, $user->fresh()->failed_login_attempts);

        $this->post('/login', ['email' => 'directeur@a.cm', 'password' => 'Test123!']);

        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_un_email_present_dans_deux_etablissements_exige_le_code(): void
    {
        $this->makeUser('A', 'partage@ecole.cm');
        $this->makeUser('B', 'partage@ecole.cm');

        $this->post('/login', ['email' => 'partage@ecole.cm', 'password' => 'Test123!'])
            ->assertSessionHasErrors('tenant_code');
        $this->assertGuest();

        $this->post('/login', [
            'email' => 'partage@ecole.cm',
            'password' => 'Test123!',
            'tenant_code' => 'B',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('B', auth()->user()->tenant->code);
    }

    public function test_un_compte_desactive_ne_peut_pas_se_connecter(): void
    {
        $user = $this->makeUser('A', 'ancien@a.cm');
        $user->update(['status' => 'INACTIVE']);

        $this->post('/login', ['email' => 'ancien@a.cm', 'password' => 'Test123!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
