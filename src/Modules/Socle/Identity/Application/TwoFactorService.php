<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use OTPHP\TOTP;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Domain\AccessToken;
use Scholaris\Modules\Socle\Identity\Domain\Exception\TwoFactorRejected;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoBackupCodeRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoOneTimeCodeRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Double authentification : code TOTP (RFC 6238, spomky-labs/otphp), code reçu par SMS, codes de secours.
 * Trois essais par session ; au troisième échec le jeton est révoqué et il faut se reconnecter.
 */
final class TwoFactorService
{
    public const MAX_ATTEMPTS = 3;
    public const BACKUP_CODES = 10;
    public const SMS_TTL = 300;
    public const SMS_RESEND_AFTER = 60;
    private const BACKUP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const PURPOSE = 'two_factor';

    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly PdoBackupCodeRepository $backupCodes,
        private readonly PdoOneTimeCodeRepository $codes,
        private readonly SecretCipher $cipher,
        private readonly TotpMatcher $totp,
        private readonly SecurityNotifier $notifier,
        private readonly AuthService $auth,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    /**
     * Prépare l'activation : génère un secret (non encore confirmé) et renvoie la clé et l'URI à afficher en code QR.
     *
     * @return array{secret: string, secret_grouped: string, otpauth_uri: string}
     */
    public function setup(User $user): array
    {
        if ($user->hasTwoFactor()) {
            throw HttpException::conflict('La double authentification est déjà activée sur ce compte.');
        }
        $totp = TOTP::generate();
        $label = $user->email ?? $user->phone ?? $user->id;
        $totp->setLabel($label !== '' ? $label : 'SCHOLARIS');
        $totp->setIssuer('SCHOLARIS');
        $secret = $totp->getSecret();
        $this->users->storeTwoFactorSecret($user->tenantId, $user->id, $this->cipher->encrypt($secret), $this->moment->iso());

        return [
            'secret' => $secret,
            'secret_grouped' => trim(chunk_split($secret, 4, ' ')),
            'otpauth_uri' => $totp->getProvisioningUri(),
        ];
    }

    /**
     * Confirme l'activation avec un premier code, ouvre la session et génère les codes de secours (affichés une seule fois).
     *
     * @return list<string>
     * @throws TwoFactorRejected
     */
    public function confirm(User $user, AccessToken $token, string $code): array
    {
        if ($user->twoFactorSecret === null) {
            throw HttpException::conflict('Commencez par générer une clé de double authentification.');
        }
        if ($user->hasTwoFactor()) {
            throw HttpException::conflict('La double authentification est déjà activée sur ce compte.');
        }
        $step = $this->matchingStep($user, $code);
        if ($step === null) {
            $this->fail($token);
        }
        $now = $this->moment->iso();
        $this->users->confirmTwoFactor($user->tenantId, $user->id, $step, $now);
        $plain = $this->generateBackupCodes($user);
        $this->tokens->markTwoFactorVerified($token->tenantId, $token->id, $now);
        $this->audit->append($user->tenantId, $user->id, 'identity.two_factor_enabled', 'user', $user->id);

        return $plain;
    }

    /**
     * Vérifie un code TOTP ou SMS et ouvre la session.
     *
     * @throws TwoFactorRejected
     */
    public function verify(User $user, AccessToken $token, string $code, string $method, bool $trustDevice): void
    {
        if ($method === 'sms') {
            $ok = $this->checkSmsCode($user, $code);
        } else {
            $step = $user->hasTwoFactor() ? $this->matchingStep($user, $code) : null;
            $ok = $step !== null && $this->users->consumeTwoFactorStep($user->tenantId, $user->id, $step);
        }
        if (!$ok) {
            $this->fail($token);
        }
        $this->succeed($user, $token, $trustDevice, 'totp_or_sms');
    }

    /**
     * Utilise un code de secours (usage unique).
     *
     * @return int codes de secours restants
     * @throws TwoFactorRejected
     */
    public function useBackupCode(User $user, AccessToken $token, string $code, bool $trustDevice): int
    {
        $ok = $this->backupCodes->consume($user->tenantId, $user->id, self::backupHash($code, $user->id), $this->moment->iso());
        if (!$ok) {
            $this->fail($token);
        }
        $this->succeed($user, $token, $trustDevice, 'backup_code');

        return $this->backupCodes->countUnused($user->tenantId, $user->id);
    }

    /**
     * Envoie un code par SMS (alternative au code de l'application).
     *
     * @return array{channel: string, masked_to: string, expires_in: int, resend_in: int}
     */
    public function sendSmsCode(User $user): array
    {
        if ($user->phone === null) {
            throw HttpException::conflict('Aucun numéro de téléphone n\'est enregistré pour recevoir un code.');
        }
        $now = $this->moment->iso();
        $open = $this->codes->latestOpen($user->tenantId, $user->id, [self::PURPOSE]);
        if ($open !== null) {
            $elapsed = $this->moment->secondsBetween($open['expires_at'], $now) + self::SMS_TTL;
            if ($elapsed < self::SMS_RESEND_AFTER) {
                throw HttpException::tooManyRequests(self::SMS_RESEND_AFTER - $elapsed);
            }
        }
        $code = sprintf('%06d', random_int(0, 999999));
        $id = (string) new Ulid();
        $this->codes->insert($id, $user->tenantId, $user->id, self::PURPOSE, 'sms', ActivationService::codeHash($code, $id), $this->moment->in(self::SMS_TTL), $now);
        $this->notifier->sendCode($user, 'sms', $code, self::SMS_TTL);

        return [
            'channel' => 'sms',
            'masked_to' => Identifier::maskPhone($user->phone),
            'expires_in' => self::SMS_TTL,
            'resend_in' => self::SMS_RESEND_AFTER,
        ];
    }

    private function succeed(User $user, AccessToken $token, bool $trustDevice, string $method): void
    {
        $this->tokens->markTwoFactorVerified($token->tenantId, $token->id, $this->moment->iso());
        if ($trustDevice) {
            $this->auth->trustDevice($token->tenantId, $token->deviceId);
        }
        $this->audit->append($user->tenantId, $user->id, 'identity.two_factor_verified', 'user', $user->id, null, ['method' => $method, 'trusted' => $trustDevice]);
    }

    private function checkSmsCode(User $user, string $code): bool
    {
        $row = $this->codes->latestOpen($user->tenantId, $user->id, [self::PURPOSE]);
        if ($row === null || $row['expires_at'] <= $this->moment->iso()) {
            return false;
        }
        if (!hash_equals($row['code_hash'], ActivationService::codeHash($code, $row['id']))) {
            $this->codes->incrementAttempts($row['tenant_id'], $row['id']);

            return false;
        }
        $this->codes->consume($row['tenant_id'], $row['id'], $this->moment->iso());

        return true;
    }

    /** Compte l'échec ; au troisième, révoque le jeton. @throws TwoFactorRejected */
    private function fail(AccessToken $token): never
    {
        $attempts = $this->tokens->incrementTwoFactorAttempts($token->tenantId, $token->id);
        $left = max(0, self::MAX_ATTEMPTS - $attempts);
        if ($left === 0) {
            $this->tokens->revoke($token->tenantId, $token->id, $this->moment->iso());
        }

        throw new TwoFactorRejected($left);
    }

    /** Pas TOTP correspondant au code, ou null (voir TotpMatcher). */
    private function matchingStep(User $user, string $code): ?int
    {
        return $this->totp->matchingStep($user, $code);
    }

    /** @return list<string> */
    private function generateBackupCodes(User $user): array
    {
        $plain = [];
        $hashes = [];
        $max = strlen(self::BACKUP_ALPHABET) - 1;
        for ($i = 0; $i < self::BACKUP_CODES; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= self::BACKUP_ALPHABET[random_int(0, $max)];
            }
            $plain[] = $code;
            $hashes[] = self::backupHash($code, $user->id);
        }
        $this->backupCodes->replaceAll($user->tenantId, $user->id, $hashes, $this->moment->iso());

        return $plain;
    }

    public static function backupHash(string $code, string $userId): string
    {
        return hash_hmac('sha256', strtoupper((string) preg_replace('/[\s-]+/', '', $code)), $userId);
    }
}
