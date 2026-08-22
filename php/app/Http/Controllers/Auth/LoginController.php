<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Connexion par email et mot de passe.
 *
 * Un meme email peut exister dans plusieurs etablissements (unicite sur le
 * couple tenant_id + email) : quand le cas se presente, le formulaire redemande
 * le code de l'etablissement plutot que de choisir arbitrairement un compte.
 */
class LoginController extends Controller
{
    /** Nombre d'echecs consecutifs avant verrouillage du compte. */
    private const MAX_ATTEMPTS = 5;

    /** Duree du verrouillage, en minutes. */
    private const LOCK_MINUTES = 15;

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_code' => ['nullable', 'string'],
        ]);

        $user = $this->findUser($credentials['email'], $credentials['tenant_code'] ?? null);

        if ($user === null) {
            $this->fail('auth.failed');
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => "Compte verrouille jusqu'a ".$user->locked_until->format('H:i').'.',
            ]);
        }

        if (! Hash::check($credentials['password'], $user->password_hash)) {
            $this->registerFailedAttempt($user);
            $this->fail('auth.failed');
        }

        if ($user->status !== 'ACTIVE') {
            $this->fail('Ce compte est desactive. Contactez l administration de votre etablissement.');
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login' => now(),
        ])->save();

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'resource' => 'auth',
            'ip_address' => $request->ip(),
        ]);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Recherche le compte correspondant a l'email. Sans code d'etablissement,
     * la recherche n'aboutit que si l'email est unique sur toute la plateforme.
     */
    private function findUser(string $email, ?string $tenantCode): ?User
    {
        $query = User::query()->where('email', $email);

        if ($tenantCode !== null && $tenantCode !== '') {
            return $query->whereHas('tenant', fn ($tenant) => $tenant->where('code', $tenantCode))->first();
        }

        $matches = $query->limit(2)->get();

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'tenant_code' => 'Cet email existe dans plusieurs etablissements : precisez le code.',
            ]);
        }

        return $matches->first();
    }

    /**
     * Incremente le compteur d'echecs et verrouille le compte au seuil atteint.
     */
    private function registerFailedAttempt(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= self::MAX_ATTEMPTS ? now()->addMinutes(self::LOCK_MINUTES) : null,
        ])->save();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'email' => $message === 'auth.failed' ? __('auth.failed') : $message,
        ]);
    }
}
