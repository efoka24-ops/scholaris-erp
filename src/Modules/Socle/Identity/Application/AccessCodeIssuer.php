<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoLoginThrottleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoOneTimeCodeRepository;

/**
 * Code d'accès créé par l'administrateur de plateforme pour un compte précis (le compte ne reçoit pas son code).
 *
 * Même table et même empreinte que le parcours /auth/activation : le code se saisit tel quel sur l'écran d'activation
 * (compte en attente) ou de mot de passe oublié (compte actif). Le code en clair n'est rendu qu'une fois, jamais stocké
 * ni journalisé. Les codes ouverts du compte sont invalidés ; 5 codes au plus par heure et par compte.
 */
final class AccessCodeIssuer
{
    public const CHANNEL = 'platform';
    public const MAX_PER_HOUR = 5;

    public function __construct(
        private readonly PdoOneTimeCodeRepository $codes,
        private readonly PdoLoginThrottleRepository $throttle,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    /** @return array{code: string, purpose: string, expires_at: string, expires_in: int, login: string} */
    public function issue(User $user, string $actorId): array
    {
        if ($user->status === User::LOCKED) {
            throw HttpException::conflict('Ce compte est bloqué : débloquez-le avant de lui créer un code d\'accès.');
        }
        $this->assertQuota($user);

        $now = $this->moment->iso();
        $purpose = $user->status === User::PENDING ? ActivationService::ACTIVATION : ActivationService::RESET;
        $this->codes->consumeOpen($user->tenantId, $user->id, [ActivationService::ACTIVATION, ActivationService::RESET], $now);
        $code = sprintf('%06d', random_int(0, 999999));
        $id = (string) new Ulid();
        $expiresAt = $this->moment->in(ActivationService::CODE_TTL);
        $this->codes->insert($id, $user->tenantId, $user->id, $purpose, self::CHANNEL, ActivationService::codeHash($code, $id), $expiresAt, $now);
        $this->openVerificationWindow($user, $now);
        $this->audit->append($user->tenantId, $actorId, 'identity.access_code_issued', 'user', $user->id, null, ['purpose' => $purpose, 'by' => 'platform']);

        return [
            'code' => $code,
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
            'expires_in' => ActivationService::CODE_TTL,
            'login' => (string) ($user->email ?? $user->phone),
        ];
    }

    /** Vrai si ce code est le code ouvert, non expiré, du compte (pour le renvoyer sans le régénérer). */
    public function isOpen(User $user, string $code): bool
    {
        $row = $this->codes->latestOpen($user->tenantId, $user->id, [ActivationService::ACTIVATION, ActivationService::RESET]);

        return $row !== null
            && $row['expires_at'] > $this->moment->iso()
            && hash_equals($row['code_hash'], ActivationService::codeHash($code, $row['id']));
    }

    private function assertQuota(User $user): void
    {
        $issued = $this->codes->issuedSince($user->tenantId, $user->id, self::CHANNEL, $this->moment->in(-3600));
        if ($issued['count'] >= self::MAX_PER_HOUR) {
            $elapsed = $issued['oldest'] === null ? 0 : $this->moment->secondsBetween($issued['oldest'], $this->moment->iso());
            throw HttpException::tooManyRequests(max(1, 3600 - $elapsed));
        }
    }

    /** La vérification publique exige une demande récente pour l'identifiant saisi : on l'ouvre pour chaque identifiant du compte. */
    private function openVerificationWindow(User $user, string $now): void
    {
        foreach ([$user->phone, $user->email] as $value) {
            if ($value === null) {
                continue;
            }
            $key = Identifier::fromInput($value)->key();
            $state = $this->throttle->state($key);
            $state['last_otp_at'] = $now;
            $state['otp_attempts'] = 0;
            $this->throttle->save($key, $state, $now);
        }
    }
}
