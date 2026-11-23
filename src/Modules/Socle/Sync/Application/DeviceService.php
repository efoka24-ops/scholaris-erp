<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Sync\Domain\BatchSignature;
use Scholaris\Modules\Socle\Sync\Infrastructure\DeviceKeyCipher;
use Scholaris\Modules\Socle\Sync\Infrastructure\DeviceRepository;

/**
 * Appareils : inscription (clé aléatoire de 256 bits rendue UNE fois, conservée chiffrée), liste, révocation, authentification
 * d'un lot par signature. La clé n'est jamais journalisée, ni dans l'audit, ni dans les logs.
 */
final class DeviceService
{
    private const DEVICE_ID = '/^[0-9A-Za-z]{26}$/D';

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly DeviceKeyCipher $cipher,
        private readonly AuditLog $audit,
        private readonly SyncClock $clock,
    ) {
    }

    /** @return array<string, mixed> l'appareil, avec `key` (clé en clair, à ne rendre qu'à cet appel) */
    public function register(Principal $principal, string $establishmentId, string $label): array
    {
        $key = bin2hex(random_bytes(32));
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->devices->insert([
            'id' => $id,
            'tenant_id' => $principal->tenantId,
            'establishment_id' => $establishmentId,
            'label' => $label,
            'key_cipher' => $this->cipher->encrypt($key),
            'registered_by' => $principal->userId,
            'created_at' => $now,
            'last_seen_at' => null,
            'revoked_at' => null,
            'revoked_by' => null,
        ]);
        $device = $this->find($principal->tenantId, $establishmentId, $id);
        $this->audit->append($principal->tenantId, $principal->userId, 'sync.device_registered', 'sync_device', $id, null, [
            'establishment_id' => $establishmentId,
            'label' => $label,
        ]);

        return $device + ['key' => $key];
    }

    /** @return array<string, mixed> */
    public function find(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->devices->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Appareil introuvable.');
    }

    /** @return list<array<string, mixed>> jusqu'à $limit appareils après $afterId, par identifiant croissant */
    public function page(string $tenantId, string $establishmentId, ?string $afterId, int $limit): array
    {
        return $this->devices->page($tenantId, $establishmentId, $afterId, $limit);
    }

    /**
     * Révocation idempotente : la date de première révocation est conservée.
     *
     * @return array<string, mixed>
     */
    public function revoke(Principal $principal, string $establishmentId, string $id): array
    {
        $this->find($principal->tenantId, $establishmentId, $id);
        if ($this->devices->revoke($principal->tenantId, $establishmentId, $id, $this->clock->now(), $principal->userId) === 1) {
            $this->audit->append($principal->tenantId, $principal->userId, 'sync.device_revoked', 'sync_device', $id, null, ['establishment_id' => $establishmentId]);
        }

        return $this->find($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Authentifie un lot : l'appareil doit exister DANS ce client et cet établissement, ne pas être révoqué, et la signature
     * doit correspondre. Appareil inconnu, révoqué ou signature fausse donnent la même réponse 401 (aucune indication sur
     * l'existence de l'appareil) ; une signature est toujours calculée, même pour un appareil inconnu.
     *
     * @return array<string, mixed> l'appareil
     */
    public function authenticate(string $tenantId, string $establishmentId, string $deviceId, string $batchId, string $body, string $signature): array
    {
        $device = preg_match(self::DEVICE_ID, $deviceId) === 1 ? $this->devices->find($tenantId, $establishmentId, $deviceId) : null;
        $key = $device !== null ? $this->cipher->decrypt((string) $device['key_cipher']) : bin2hex(random_bytes(32));
        $valid = BatchSignature::matches($key, $deviceId, $batchId, $body, $signature);
        // PP7 : un appareil verrouillé (5 codes erronés) est refusé comme un appareil révoqué.
        if ($device === null || $device['revoked_at'] !== null || ($device['locked_at'] ?? null) !== null || !$valid) {
            throw HttpException::unauthorized('Appareil ou signature invalide.');
        }

        return $device;
    }

    public function touch(string $tenantId, string $establishmentId, string $id): void
    {
        $this->devices->touch($tenantId, $establishmentId, $id, $this->clock->now());
    }
}
