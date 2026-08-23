<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;

/**
 * Connexion et deconnexion.
 */
final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        $session = $this->app->session();

        return $this->view('auth.login', [
            'old' => $session->pullOldInput(),
            'error' => $session->pullFlash('login_error'),
        ]);
    }

    public function login(Request $request): Response
    {
        $email = $request->string('email');
        $password = $request->string('password');
        $tenantCode = $request->string('tenant_code');

        if ($email === '' || $password === '') {
            return $this->backToLogin($request, 'Renseignez votre email et votre mot de passe.');
        }

        // Un email present dans plusieurs etablissements ne peut pas etre
        // resolu sans son code : on le demande plutot que d'en choisir un.
        if ($tenantCode === '' && $this->emailIsAmbiguous($email)) {
            return $this->backToLogin(
                $request,
                'Cet email existe dans plusieurs etablissements : precisez le code etablissement.'
            );
        }

        $result = $this->app->auth()->attempt($email, $password, $tenantCode ?: null, $request->ip());

        if (! $result['ok']) {
            return $this->backToLogin($request, $result['error'] ?? 'Connexion impossible.');
        }

        // Jeton CSRF renouvele : celui de la session anonyme ne doit pas
        // survivre a l'authentification.
        $this->app->csrf()->rotate();
        $this->app->rbac()->reset();

        return $this->redirect('/dashboard');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth()->logout();

        return $this->redirect('/login');
    }

    private function emailIsAmbiguous(string $email): bool
    {
        $count = $this->app->db()->scalar(
            'SELECT COUNT(*) FROM users WHERE email = :email AND deleted_at IS NULL',
            ['email' => $email]
        );

        return (int) $count > 1;
    }

    private function backToLogin(Request $request, string $message): Response
    {
        $session = $this->app->session();
        $session->flash('login_error', $message);
        $session->flashInput($request->all());

        return $this->redirect('/login');
    }
}
