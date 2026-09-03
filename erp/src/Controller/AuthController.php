<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Auth\Auth;
use Scholaris\Auth\Totp;
use Scholaris\Http\Request;
use Scholaris\Http\Response;

/**
 * Connexion, deconnexion, activation de compte et changement de mot de passe
 * obligatoire.
 */
final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        $session = $this->app->session();

        return $this->view('auth.login', [
            'old' => $session->pullOldInput(),
            'error' => $session->pullFlash('login_error'),
            'mfaRequired' => $session->pullFlash('mfa_required') !== null,
        ]);
    }

    public function login(Request $request): Response
    {
        $email = $request->string('email');
        $password = $request->string('password');
        $tenantCode = $request->string('tenant_code');
        $totpCode = $request->string('totp_code');

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

        $result = $this->app->auth()->attempt($email, $password, $tenantCode ?: null, $request->ip(), $totpCode ?: null);

        if (! $result['ok']) {
            if (($result['mfa_required'] ?? false) === true) {
                $this->app->session()->flash('mfa_required', '1');
            }

            return $this->backToLogin($request, $result['error'] ?? 'Connexion impossible.');
        }

        // Jeton CSRF renouvele : celui de la session anonyme ne doit pas
        // survivre a l'authentification.
        $this->app->csrf()->rotate();
        $this->app->rbac()->reset();
        $this->app->session()->forget('mfa_enroll_dismissed');

        return $this->redirect('/dashboard');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth()->logout();

        return $this->redirect('/login');
    }

    /**
     * Ecran de changement de mot de passe obligatoire.
     *
     * Atteint soit volontairement, soit force par Application::handle tant
     * que must_change_password vaut vrai : les deux chemins menent au meme
     * formulaire.
     */
    public function showChangePassword(Request $request): Response
    {
        return $this->view('auth.change-password', [
            'forced' => $this->app->auth()->mustChangePassword(),
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $current = $request->string('current_password');
        $password = $request->string('password');
        $confirmation = $request->string('password_confirmation');
        $user = $this->app->auth()->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        // Le message a le temps d'etre affiche : shareViewData() partage la
        // banniere generique avant meme que ce controleur ne s'execute, donc
        // le flash est consomme une fois, et une seule, sur la page suivante.
        if (! password_verify($current, (string) $user['password_hash'])) {
            return $this->redirectWithError('/mot-de-passe/changer', 'Le mot de passe actuel est incorrect.');
        }

        if (mb_strlen($password) < 8) {
            return $this->redirectWithError('/mot-de-passe/changer', 'Le nouveau mot de passe doit contenir au moins 8 caracteres.');
        }

        if ($password !== $confirmation) {
            return $this->redirectWithError('/mot-de-passe/changer', 'La confirmation ne correspond pas au nouveau mot de passe.');
        }

        $this->app->db()->execute(
            'UPDATE users SET password_hash = :hash, must_change_password = 0, updated_at = :updated_at WHERE id = :id',
            ['hash' => Auth::hash($password), 'updated_at' => date('Y-m-d H:i:s'), 'id' => $user['id']]
        );

        $this->app->auth()->refresh();

        return $this->redirectWithSuccess('/dashboard', 'Votre mot de passe a ete change.');
    }

    /**
     * Lien d'activation : le titulaire d'un compte cree par un tiers choisit
     * lui-meme son mot de passe, sans devoir se fier a celui recu par mail.
     */
    public function showActivate(Request $request): Response
    {
        $token = (string) $request->attribute('token');
        $row = $this->app->accountActivation()->find($token);

        return $this->view('auth.activate', [
            'token' => $token,
            'valid' => $row !== null,
            // Cle dediee : cette page est publique, et shareViewData() a deja
            // consomme le flash "error" generique avant que ce controleur ne
            // s'execute — le meme detour qu'utilise PublicController pour ses
            // propres formulaires anonymes.
            'error' => $this->app->session()->pullFlash('activation_error'),
        ]);
    }

    public function activate(Request $request): Response
    {
        $token = (string) $request->attribute('token');
        $row = $this->app->accountActivation()->find($token);

        if ($row === null) {
            $this->app->session()->flash('activation_error', 'Ce lien d activation est invalide ou a expire.');

            return $this->redirect('/activation/'.$token);
        }

        $password = $request->string('password');
        $confirmation = $request->string('password_confirmation');

        if (mb_strlen($password) < 8) {
            $this->app->session()->flash('activation_error', 'Le mot de passe doit contenir au moins 8 caracteres.');

            return $this->redirect('/activation/'.$token);
        }

        if ($password !== $confirmation) {
            $this->app->session()->flash('activation_error', 'La confirmation ne correspond pas au mot de passe.');

            return $this->redirect('/activation/'.$token);
        }

        $this->app->db()->execute(
            'UPDATE users SET password_hash = :hash, must_change_password = 0, status = :status, updated_at = :updated_at WHERE id = :id',
            [
                'hash' => Auth::hash($password),
                'status' => 'ACTIVE',
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $row['user_id'],
            ]
        );

        $this->app->accountActivation()->consume((string) $row['id']);

        return $this->redirectWithSuccess('/login', 'Votre mot de passe est defini, vous pouvez vous connecter.');
    }

    /**
     * Ecran d'enrolement TOTP : un secret est genere (ou repris de la session
     * s'il en existe deja un en attente de confirmation, pour eviter d'en
     * generer un nouveau a chaque rechargement de page) et affiche avec son
     * URL otpauth://, mais rien n'est ecrit en base tant que le code n'a pas
     * ete verifie.
     */
    public function showMfaEnroll(Request $request): Response
    {
        $user = $this->app->auth()->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $secret = $this->app->session()->get('mfa_pending_secret');

        if (! is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            $this->app->session()->set('mfa_pending_secret', $secret);
        }

        $uri = Totp::provisioningUri($secret, (string) $user['email'], $this->app->env()->get('APP_NAME', 'SCHOLARIS'));

        return $this->view('auth.mfa-enroll', [
            'secret' => $secret,
            'provisioningUri' => $uri,
            'error' => $this->app->session()->pullFlash('mfa_enroll_error'),
        ]);
    }

    /**
     * Confirme l'enrolement : le code saisi doit correspondre au secret
     * genere a l'etape precedente pour prouver que l'application a bien ete
     * configuree, avant que mfa_enabled ne passe a vrai.
     */
    public function enrollMfa(Request $request): Response
    {
        $user = $this->app->auth()->user();
        $secret = $this->app->session()->get('mfa_pending_secret');

        if ($user === null || ! is_string($secret) || $secret === '') {
            return $this->redirect('/mfa/enroler');
        }

        $code = $request->string('totp_code');

        if (! Totp::verify($secret, $code)) {
            $this->app->session()->flash('mfa_enroll_error', 'Code incorrect. Reessayez avec le code affiche actuellement dans votre application.');

            return $this->redirect('/mfa/enroler');
        }

        $this->app->db()->execute(
            'UPDATE users SET mfa_enabled = 1, mfa_secret = :secret, updated_at = :updated_at WHERE id = :id',
            ['secret' => $secret, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $user['id']]
        );

        $this->app->session()->forget('mfa_pending_secret');
        $this->app->auth()->refresh();

        return $this->redirectWithSuccess('/dashboard', 'Double authentification activee.');
    }

    /**
     * Reporte l'enrolement : l'utilisateur ne sera plus reoriente vers cet
     * ecran pour le reste de sa session, mais y sera a nouveau invite a sa
     * prochaine connexion.
     */
    public function dismissMfaEnroll(Request $request): Response
    {
        $this->app->session()->set('mfa_enroll_dismissed', true);

        return $this->redirect('/dashboard');
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
