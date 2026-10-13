<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Domain\Exception\CodeRejected;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\PasswordPolicy;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoDeviceRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoLoginThrottleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoOneTimeCodeRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoPeopleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Activation d'un compte inscrit par l'établissement, et réinitialisation du mot de passe, par code à usage
 * unique : 6 chiffres, valable 5 minutes, 3 essais, renvoi possible après 60 secondes.
 *
 * Les compteurs d'essais et de demandes sont tenus par identifiant saisi (haché), connu ou non : la réponse à une
 * demande ou à une vérification ne dit jamais si un compte existe.
 */
final class ActivationService
{
    public const CODE_TTL = 300;
    public const RESEND_AFTER = 60;
    public const MAX_ATTEMPTS = 3;
    public const MAX_REQUESTS_PER_HOUR = 5;
    public const TICKET_TTL = 600;

    private const ACTIVATION = 'activation';
    private const RESET = 'reset';

    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly PdoPeopleRepository $people,
        private readonly PdoOneTimeCodeRepository $codes,
        private readonly PdoLoginThrottleRepository $throttle,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly PdoDeviceRepository $devices,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly SecurityNotifier $notifier,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    /**
     * Envoie un code d'activation (compte en attente) ou de réinitialisation (compte existant).
     *
     * @return array{channel: string, masked_to: string, expires_in: int, resend_in: int}
     */
    public function request(Identifier $identifier, ?string $channel, bool $reset): array
    {
        $key = $identifier->key();
        $state = $this->throttle->state($key);
        $now = $this->moment->iso();

        if ($state['last_otp_at'] !== null) {
            $elapsed = $this->moment->secondsBetween($state['last_otp_at'], $now);
            if ($elapsed < self::RESEND_AFTER) {
                throw HttpException::tooManyRequests(self::RESEND_AFTER - $elapsed);
            }
        }
        if ($state['otp_window_start'] === null || $this->moment->secondsBetween($state['otp_window_start'], $now) >= 3600) {
            $state['otp_window_start'] = $now;
            $state['otp_count'] = 0;
        }
        if ($state['otp_count'] >= self::MAX_REQUESTS_PER_HOUR) {
            throw HttpException::tooManyRequests(max(1, 3600 - $this->moment->secondsBetween($state['otp_window_start'], $now)));
        }
        $state['otp_count']++;
        $state['last_otp_at'] = $now;
        $state['otp_attempts'] = 0;
        $this->throttle->save($key, $state, $now);

        $target = $this->eligibleUser($identifier, $reset);
        if ($target === null) {
            $channel = $channel ?? ($identifier->isEmail ? 'email' : 'sms');

            return [
                'channel' => $channel,
                'masked_to' => ($channel === 'email') === $identifier->isEmail ? $identifier->mask() : '•••@•••',
                'expires_in' => self::CODE_TTL,
                'resend_in' => self::RESEND_AFTER,
            ];
        }

        $channel = $this->resolveChannel($target, $channel, $identifier);
        $code = sprintf('%06d', random_int(0, 999999));
        $id = (string) new Ulid();
        $this->codes->insert(
            $id,
            $target->tenantId,
            $target->id,
            $reset ? self::RESET : self::ACTIVATION,
            $channel,
            self::codeHash($code, $id),
            $this->moment->in(self::CODE_TTL),
            $now,
        );
        $this->notifier->sendCode($target, $channel, $code, self::CODE_TTL);

        return [
            'channel' => $channel,
            'masked_to' => $channel === 'email' && $target->email !== null ? Identifier::maskEmail($target->email) : Identifier::maskPhone((string) $target->phone),
            'expires_in' => self::CODE_TTL,
            'resend_in' => self::RESEND_AFTER,
        ];
    }

    /**
     * Vérifie le code ; renvoie le ticket qui autorise l'étape « nouveau mot de passe ».
     *
     * @throws CodeRejected
     */
    public function verify(Identifier $identifier, string $code): string
    {
        $key = $identifier->key();
        $state = $this->throttle->state($key);
        $now = $this->moment->iso();

        if ($state['last_otp_at'] === null || $this->moment->secondsBetween($state['last_otp_at'], $now) > self::CODE_TTL) {
            throw CodeRejected::expired();
        }
        if ($state['otp_attempts'] >= self::MAX_ATTEMPTS) {
            throw CodeRejected::exhausted();
        }

        $row = null;
        $owner = null;
        foreach ($this->users->findByIdentifier($identifier) as $candidate) {
            $row = $this->codes->latestOpen($candidate->tenantId, $candidate->id, [self::ACTIVATION, self::RESET]);
            if ($row !== null) {
                $owner = $candidate;
                break;
            }
        }

        $valid = $row !== null
            && $row['expires_at'] > $now
            && hash_equals($row['code_hash'], self::codeHash($code, $row['id']));

        if ($row === null || !$valid) {
            $state['otp_attempts']++;
            $this->throttle->save($key, $state, $now);
            if ($row !== null) {
                $this->codes->incrementAttempts($row['tenant_id'], $row['id']);
            }
            $left = self::MAX_ATTEMPTS - $state['otp_attempts'];
            if ($left <= 0) {
                if ($row !== null) {
                    $this->codes->consume($row['tenant_id'], $row['id'], $now);
                }
                throw CodeRejected::exhausted();
            }

            throw CodeRejected::invalid($left);
        }

        $ticket = bin2hex(random_bytes(32));
        $this->codes->markVerified($row['tenant_id'], $row['id'], hash('sha256', $ticket), $this->moment->in(self::TICKET_TTL), $now);
        $state['otp_attempts'] = 0;
        $state['last_otp_at'] = null;
        $this->throttle->save($key, $state, $now);

        if ($owner !== null) {
            $this->audit->append($owner->tenantId, $owner->id, 'identity.code_verified', 'user', $owner->id);
        }

        return $ticket;
    }

    /** Définit le mot de passe (activation ou réinitialisation) avec le ticket obtenu après vérification du code. */
    public function setPassword(string $ticket, string $password): void
    {
        $row = $ticket === '' ? null : $this->codes->findByTicket(hash('sha256', $ticket), $this->moment->iso());
        $user = $row === null ? null : $this->users->find($row['tenant_id'], $row['user_id']);
        if ($row === null || $user === null) {
            throw HttpException::validation(['ticket' => ['Cette étape a expiré. Recommencez la procédure depuis le début.']], 'Étape expirée.');
        }

        $person = $this->people->find($user->tenantId, $user->personId);
        $personal = array_filter([
            $user->phone,
            $user->phone === null ? null : ltrim($user->phone, '+'),
            $user->phone === null ? null : (string) preg_replace('/^\+237/', '', $user->phone),
            $user->email,
            $user->email === null ? null : explode('@', $user->email)[0],
            $person['first_name'] ?? null,
            $person['last_name'] ?? null,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
        $violations = $this->policy->violations($password, array_values($personal));
        if ($violations !== []) {
            throw HttpException::validation(['password' => $violations], 'Le mot de passe choisi n\'est pas assez sûr.');
        }

        $now = $this->moment->iso();
        $reset = $row['purpose'] === self::RESET;
        $this->users->setPassword($user->tenantId, $user->id, $this->hasher->hash($password), $now);
        $this->codes->consume($user->tenantId, $row['id'], $now);
        $this->clearThrottles($user);

        if ($reset) {
            $this->tokens->revokeForUser($user->tenantId, $user->id, $now);
            $this->devices->clearTrust($user->tenantId, $user->id);
        }
        $this->audit->append($user->tenantId, $user->id, $reset ? 'identity.password_reset' : 'identity.account_activated', 'user', $user->id);
        $this->notifier->passwordChanged($user);
    }

    public static function codeHash(string $code, string $codeId): string
    {
        return hash_hmac('sha256', trim($code), $codeId);
    }

    private function eligibleUser(Identifier $identifier, bool $reset): ?User
    {
        foreach ($this->users->findByIdentifier($identifier) as $user) {
            if ($reset || $user->status === User::PENDING) {
                return $user;
            }
        }

        return null;
    }

    private function resolveChannel(User $user, ?string $requested, Identifier $identifier): string
    {
        $available = [];
        if ($user->phone !== null) {
            $available[] = 'sms';
        }
        if ($user->email !== null) {
            $available[] = 'email';
        }
        $preferred = $requested ?? ($identifier->isEmail ? 'email' : 'sms');

        return in_array($preferred, $available, true) ? $preferred : ($available[0] ?? 'sms');
    }

    private function clearThrottles(User $user): void
    {
        foreach ([$user->phone, $user->email] as $value) {
            if ($value !== null) {
                $key = Identifier::fromInput($value)->key();
                $state = $this->throttle->state($key);
                $state['failed_count'] = 0;
                $state['locked_until'] = null;
                $this->throttle->save($key, $state, $this->moment->iso());
            }
        }
    }
}
