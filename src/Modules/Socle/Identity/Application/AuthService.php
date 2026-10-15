<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Domain\AccessToken;
use Scholaris\Modules\Socle\Identity\Domain\Exception\AccountLocked;
use Scholaris\Modules\Socle\Identity\Domain\Exception\InvalidCredentials;
use Scholaris\Modules\Socle\Identity\Domain\Exception\LockedOut;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoDeviceRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoLoginThrottleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Connexion par identifiant (téléphone ou e-mail) et mot de passe, verrouillage après 5 échecs pendant 15 minutes,
 * détection des nouveaux appareils, sessions et appareils (liste, révocation, « déconnecter partout », « Ce n'est pas moi »).
 */
final class AuthService
{
    public const MAX_FAILURES = 5;
    public const LOCK_SECONDS = 900;
    public const TRUST_SECONDS = 2592000; // 30 jours

    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly PdoDeviceRepository $devices,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly PdoLoginThrottleRepository $throttle,
        private readonly TokenService $tokenService,
        private readonly PasswordHasher $hasher,
        private readonly PermissionResolver $resolver,
        private readonly SecurityNotifier $notifier,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
        private readonly TwoFactorPolicy $twoFactorPolicy,
    ) {
    }

    /**
     * @throws InvalidCredentials identifiant inconnu ou mot de passe faux (même réponse)
     * @throws LockedOut trop d'échecs récents
     * @throws AccountLocked compte bloqué par « Ce n'est pas moi »
     */
    public function login(string $rawIdentifier, string $password, bool $remember, ClientInfo $client): LoginResult
    {
        $identifier = Identifier::fromInput($rawIdentifier);
        $key = $identifier->key();
        $state = $this->throttle->state($key);
        $now = $this->moment->iso();

        if ($state['locked_until'] !== null) {
            if ($state['locked_until'] > $now) {
                throw new LockedOut($state['locked_until'], $this->moment->secondsBetween($now, $state['locked_until']));
            }
            $state['locked_until'] = null;
            $state['failed_count'] = 0;
        }

        $candidates = array_values(array_filter($this->users->findByIdentifier($identifier), static fn (User $u): bool => $u->passwordHash !== null));
        $matched = null;
        foreach ($candidates as $candidate) {
            if ($this->hasher->verify($password, (string) $candidate->passwordHash)) {
                $matched = $candidate;
                break;
            }
        }
        if ($candidates === []) {
            $this->hasher->burn($password);
        }

        if ($matched === null) {
            $state['failed_count']++;
            if ($state['failed_count'] >= self::MAX_FAILURES) {
                $state['locked_until'] = $this->moment->in(self::LOCK_SECONDS);
                $this->throttle->save($key, $state, $now);
                foreach ($candidates as $candidate) {
                    $this->notifier->lockedOut($candidate, $state['locked_until']);
                    $this->audit->append($candidate->tenantId, $candidate->id, 'identity.login_locked', 'user', $candidate->id, null, ['locked_until' => $state['locked_until']], $client->ip, $client->deviceName());
                }

                throw new LockedOut($state['locked_until'], self::LOCK_SECONDS);
            }
            $this->throttle->save($key, $state, $now);

            throw new InvalidCredentials(self::MAX_FAILURES - $state['failed_count']);
        }

        if ($matched->status === User::LOCKED) {
            throw new AccountLocked();
        }

        $state['failed_count'] = 0;
        $state['locked_until'] = null;
        $this->throttle->save($key, $state, $now);

        $device = $this->devices->findActiveByFingerprint($matched->tenantId, $matched->id, $client->fingerprint());
        $newDevice = $device === null;
        if ($device === null) {
            $deviceId = $this->devices->insert($matched->tenantId, $matched->id, $client->fingerprint(), $client->deviceName(), $client->location, $client->ip, $now);
            $trusted = false;
        } else {
            $deviceId = $device['id'];
            $trusted = $device['trusted_until'] !== null && $device['trusted_until'] > $now;
            $this->devices->touch($matched->tenantId, $deviceId, $client->ip, $now);
        }

        $sensitive = $this->resolver->hasSensitiveRole($matched->tenantId, $matched->personId);
        $platformAdmin = $this->resolver->isPlatformAdmin($matched->tenantId, $matched->personId);
        // L'administrateur de plateforme n'a jamais de dispense d'appareil de confiance.
        $needsTwoFactor = $this->twoFactorPolicy->requiredFor($sensitive, $platformAdmin) && ($platformAdmin || !($matched->hasTwoFactor() && $trusted));
        $issued = $this->tokenService->issue($matched->tenantId, $matched->id, $deviceId, $remember, $needsTwoFactor);
        $this->users->touchLogin($matched->tenantId, $matched->id, $now);
        $this->audit->append($matched->tenantId, $matched->id, 'identity.login', 'user', $matched->id, null, ['new_device' => $newDevice], $client->ip, $client->deviceName());

        if ($newDevice) {
            $this->notifier->newDevice($matched, $client->deviceName(), $client->location, $now);
        }

        return new LoginResult(
            $matched,
            $issued['token'],
            $issued['expires_at'],
            $newDevice,
            $needsTwoFactor,
            $needsTwoFactor && !$matched->hasTwoFactor(),
            $remember,
        );
    }

    public function logout(AccessToken $token): void
    {
        $this->tokens->revoke($token->tenantId, $token->id, $this->moment->iso());
    }

    /** « Déconnecter partout » : révoque tous les jetons et retire la confiance accordée aux appareils. */
    public function logoutEverywhere(string $tenantId, string $userId): void
    {
        $now = $this->moment->iso();
        $this->tokens->revokeForUser($tenantId, $userId, $now);
        $this->devices->clearTrust($tenantId, $userId);
        $this->audit->append($tenantId, $userId, 'identity.sessions_revoked', 'user', $userId);
    }

    /**
     * Appareils connectés, le plus récent d'abord ; `current` marque celui de la requête (« Celui-ci »).
     *
     * @return list<array{id: string, name: string, location: ?string, ip: ?string, last_seen_at: string, trusted_until: ?string, current: bool}>
     */
    public function devices(string $tenantId, string $userId, ?string $currentDeviceId): array
    {
        return array_map(
            static fn (array $device): array => $device + ['current' => $device['id'] === $currentDeviceId],
            $this->devices->listActive($tenantId, $userId),
        );
    }

    public function revokeDevice(string $tenantId, string $userId, string $deviceId): void
    {
        if ($this->devices->find($tenantId, $userId, $deviceId) === null) {
            throw HttpException::notFound('Appareil introuvable.');
        }
        $now = $this->moment->iso();
        $this->devices->revoke($tenantId, $deviceId, $now);
        $this->tokens->revokeForDevice($tenantId, $deviceId, $now);
        $this->audit->append($tenantId, $userId, 'identity.device_revoked', 'device', $deviceId);
    }

    /** « Ce n'est pas moi » : verrouille le compte et révoque tous les jetons et appareils. */
    public function notMe(string $tenantId, string $userId): void
    {
        $user = $this->users->find($tenantId, $userId);
        if ($user === null) {
            return;
        }
        $now = $this->moment->iso();
        $this->users->setStatus($tenantId, $userId, User::LOCKED, $now);
        $this->tokens->revokeForUser($tenantId, $userId, $now);
        $this->devices->revokeAll($tenantId, $userId, $now);
        $this->audit->append($tenantId, $userId, 'identity.not_me', 'user', $userId, ['status' => $user->status], ['status' => User::LOCKED]);
        $this->notifier->accountBlocked($user);
    }

    /** Fait confiance à l'appareil pendant 30 jours (évite la 2FA sur cet appareil). */
    public function trustDevice(string $tenantId, string $deviceId): void
    {
        $this->devices->trustUntil($tenantId, $deviceId, $this->moment->in(self::TRUST_SECONDS));
    }
}
