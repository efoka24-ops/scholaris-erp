<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Domain\AccessToken;
use Scholaris\Modules\Socle\Identity\Domain\Exception\InvalidCredentials;
use Scholaris\Modules\Socle\Identity\Domain\Exception\LockedOut;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\PasswordPolicy;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoLoginThrottleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoPeopleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Mot de passe d'une personne DÉJÀ connectée :
 *  - changement (obligatoire après un mot de passe provisoire, volontaire sinon) : l'ancien mot de passe est exigé ;
 *  - confirmation récente (POST /auth/reconfirm) : preuve de 10 minutes pour les actions sensibles d'une personne qui n'a
 *    pas de double authentification (voir PdoRecentSecondFactor).
 *
 * Les échecs partagent les compteurs et le verrouillage de la connexion (5 échecs, 15 minutes), tenus pour chacun des
 * identifiants du compte (e-mail et téléphone) : changer d'identifiant ne donne pas de nouveaux essais.
 */
final class PasswordService
{
    public const CONFIRMATION_TTL = 600;

    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly PdoLoginThrottleRepository $throttle,
        private readonly PdoPeopleRepository $people,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly SecurityNotifier $notifier,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    /**
     * Remplace le mot de passe (le courant ou provisoire est exigé). Les autres sessions du compte sont fermées.
     *
     * @throws InvalidCredentials le mot de passe actuel est faux
     * @throws LockedOut trop d'échecs récents
     */
    public function change(User $user, AccessToken $token, string $current, string $new, ClientInfo $client): void
    {
        $this->verify($user, $current, $client, 'identity.password_change_failed');

        $violations = $this->policy->violations($new, $this->personalValues($user));
        if ($new === $current) {
            $violations[] = 'Le nouveau mot de passe doit être différent de l\'actuel.';
        }
        if ($violations !== []) {
            throw HttpException::validation(['new_password' => $violations], 'Le mot de passe choisi n\'est pas assez sûr.');
        }

        $now = $this->moment->iso();
        $forced = $user->mustChangePassword;
        $this->users->changePassword($user->tenantId, $user->id, $this->hasher->hash($new), $now);
        $this->tokens->revokeForUser($user->tenantId, $user->id, $now, $token->id);
        $this->tokens->markPasswordConfirmed($token->tenantId, $token->id, $now);
        $this->audit->append($user->tenantId, $user->id, 'identity.password_changed', 'user', $user->id, null, ['forced' => $forced], $client->ip, $client->deviceName());
        if (!$forced) {
            $this->notifier->passwordChanged($user);
        }
    }

    /**
     * Confirmation par mot de passe : valable CONFIRMATION_TTL secondes. Réservée aux comptes sans double authentification
     * (les autres confirment par leur code) ; l'administrateur de plateforme n'y a jamais droit.
     *
     * @return string date de la confirmation (ISO 8601)
     * @throws InvalidCredentials
     * @throws LockedOut
     */
    public function reconfirm(User $user, AccessToken $token, string $password, bool $platformAdmin, ClientInfo $client): string
    {
        if ($platformAdmin || $user->hasTwoFactor()) {
            throw new HttpException(
                409,
                'Double authentification requise',
                'Votre compte utilise la double authentification : confirmez avec votre code.',
                'https://api.scholaris.cm/errors/second-factor-required',
            );
        }
        $this->verify($user, $password, $client, 'identity.reconfirm_failed');
        $now = $this->moment->iso();
        $this->tokens->markPasswordConfirmed($token->tenantId, $token->id, $now);
        $this->audit->append($user->tenantId, $user->id, 'identity.reconfirmed', 'user', $user->id, null, ['method' => 'password'], $client->ip, $client->deviceName());

        return $now;
    }

    /**
     * Vérifie le mot de passe avec les compteurs de la connexion.
     *
     * @throws InvalidCredentials
     * @throws LockedOut
     */
    private function verify(User $user, string $password, ClientInfo $client, string $failureEvent): void
    {
        $now = $this->moment->iso();
        $states = [];
        foreach ($this->keys($user) as $key) {
            $state = $this->throttle->state($key);
            if ($state['locked_until'] !== null) {
                if ($state['locked_until'] > $now) {
                    throw new LockedOut($state['locked_until'], $this->moment->secondsBetween($now, $state['locked_until']));
                }
                $state['locked_until'] = null;
                $state['failed_count'] = 0;
            }
            $states[$key] = $state;
        }

        if ($user->passwordHash !== null && $this->hasher->verify($password, $user->passwordHash)) {
            foreach ($states as $key => $state) {
                $state['failed_count'] = 0;
                $state['locked_until'] = null;
                $this->throttle->save($key, $state, $now);
            }

            return;
        }
        if ($user->passwordHash === null) {
            $this->hasher->burn($password);
        }

        $worst = 0;
        $lockedUntil = null;
        foreach ($states as $key => $state) {
            $state['failed_count']++;
            $worst = max($worst, $state['failed_count']);
            if ($state['failed_count'] >= AuthService::MAX_FAILURES) {
                $state['locked_until'] = $lockedUntil = $this->moment->in(AuthService::LOCK_SECONDS);
            }
            $this->throttle->save($key, $state, $now);
        }
        $this->audit->append($user->tenantId, $user->id, $failureEvent, 'user', $user->id, null, null, $client->ip, $client->deviceName());
        if ($lockedUntil !== null) {
            $this->notifier->lockedOut($user, $lockedUntil);
            $this->audit->append($user->tenantId, $user->id, 'identity.login_locked', 'user', $user->id, null, ['locked_until' => $lockedUntil], $client->ip, $client->deviceName());

            throw new LockedOut($lockedUntil, AuthService::LOCK_SECONDS);
        }

        throw new InvalidCredentials(max(0, AuthService::MAX_FAILURES - $worst));
    }

    /** @return list<string> clés de compteur des identifiants du compte */
    private function keys(User $user): array
    {
        $keys = [];
        foreach ([$user->email, $user->phone] as $value) {
            if ($value !== null && $value !== '') {
                $keys[] = Identifier::fromInput($value)->key();
            }
        }

        return $keys === [] ? [hash('sha256', 'user|' . $user->id)] : $keys;
    }

    /** @return list<string> */
    private function personalValues(User $user): array
    {
        $person = $this->people->find($user->tenantId, $user->personId);

        return array_values(array_filter([
            $user->phone,
            $user->phone === null ? null : ltrim($user->phone, '+'),
            $user->phone === null ? null : (string) preg_replace('/^\+237/', '', $user->phone),
            $user->email,
            $user->email === null ? null : explode('@', $user->email)[0],
            $person['first_name'] ?? null,
            $person['last_name'] ?? null,
        ], static fn (?string $v): bool => $v !== null && $v !== ''));
    }
}
