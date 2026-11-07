<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Sync\Infrastructure\DevicePinRepository;

/**
 * PP7 (T608) : code personnel à 6 chiffres sur un appareil partagé. Le code est haché et propre à (appareil, utilisateur).
 * Cinq échecs consécutifs verrouillent l'appareil ; un appareil verrouillé ou révoqué reçoit l'ordre d'effacer sa base locale
 * (réponse 423 avec l'en-tête `X-Device-Wipe: true`). Le code n'est jamais journalisé.
 */
final class DevicePinService
{
    public const MAX_FAILURES = 5;
    public const WIPE_HEADER = 'X-Device-Wipe';
    private const CODE = '/^\d{6}$/D';
    private const ERRORS = 'https://api.scholaris.cm/errors/';

    public function __construct(
        private readonly DeviceService $devices,
        private readonly DevicePinRepository $pins,
        private readonly AuditLog $audit,
        private readonly SyncClock $clock,
    ) {
    }

    public function setPin(Principal $principal, string $establishmentId, string $deviceId, string $code): void
    {
        $this->assertCodeFormat($code);
        $this->usableDevice($principal->tenantId, $establishmentId, $deviceId);
        $this->pins->save($principal->tenantId, $establishmentId, $deviceId, $principal->userId, password_hash($code, PASSWORD_DEFAULT), $this->clock->now());
        $this->audit->append($principal->tenantId, $principal->userId, 'sync.device_pin_set', 'sync_device', $deviceId, null, ['establishment_id' => $establishmentId]);
    }

    /**
     * Vérifie le code de l'utilisateur sur l'appareil. Succès : compteur remis à zéro. Échec : 422 avec le nombre de tentatives
     * restantes ; au cinquième échec, appareil verrouillé et ordre d'effacement (423).
     *
     * @return array{unlocked: true, wipe: false}
     */
    public function unlock(Principal $principal, string $establishmentId, string $deviceId, string $code): array
    {
        $this->usableDevice($principal->tenantId, $establishmentId, $deviceId);
        $hash = $this->pins->hashFor($principal->tenantId, $deviceId, $principal->userId);
        $valid = preg_match(self::CODE, $code) === 1 && $hash !== null && password_verify($code, $hash);
        if ($valid) {
            $this->pins->resetFailures($principal->tenantId, $establishmentId, $deviceId);

            return ['unlocked' => true, 'wipe' => false];
        }
        $failures = $this->pins->recordFailure($principal->tenantId, $establishmentId, $deviceId, self::MAX_FAILURES, $this->clock->now());
        if ($failures >= self::MAX_FAILURES) {
            $this->audit->append($principal->tenantId, $principal->userId, 'sync.device_locked', 'sync_device', $deviceId, null, [
                'establishment_id' => $establishmentId,
                'failed_unlocks' => $failures,
            ]);
            throw self::wipe('Cinq codes erronés : appareil verrouillé. Effacez la base locale ; un gestionnaire doit réinscrire l\'appareil.');
        }
        $remaining = self::MAX_FAILURES - $failures;

        throw new HttpException(422, 'Données invalides', 'Code personnel incorrect.', self::ERRORS . 'validation', [
            'code' => ['Code personnel incorrect. Tentatives restantes : ' . $remaining . '.'],
        ], ['X-Unlock-Attempts-Remaining' => (string) $remaining]);
    }

    /** @return array<string, mixed> l'appareil, ni révoqué ni verrouillé */
    private function usableDevice(string $tenantId, string $establishmentId, string $deviceId): array
    {
        $device = $this->devices->find($tenantId, $establishmentId, $deviceId);
        if ($device['revoked_at'] !== null) {
            throw self::wipe('Appareil révoqué : effacez la base locale.');
        }
        if (($device['locked_at'] ?? null) !== null) {
            throw self::wipe('Appareil verrouillé : effacez la base locale ; un gestionnaire doit réinscrire l\'appareil.');
        }

        return $device;
    }

    private function assertCodeFormat(string $code): void
    {
        if (preg_match(self::CODE, $code) !== 1) {
            throw HttpException::validation(['code' => ['Le code personnel compte exactement 6 chiffres.']]);
        }
    }

    private static function wipe(string $detail): HttpException
    {
        return new HttpException(423, 'Appareil verrouillé', $detail, self::ERRORS . 'device-locked', [], [self::WIPE_HEADER => 'true']);
    }
}
