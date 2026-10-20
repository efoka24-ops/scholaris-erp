<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Sync\Infrastructure\BatchRepository;
use Scholaris\Modules\Socle\Sync\Infrastructure\Db;

/**
 * Réception d'un lot hors ligne (API-04). Ordre des contrôles : taille du corps compressé (413), appareil et signature (401),
 * décompression et forme (400, 413, 422), puis idempotence par identifiant de lot (rejeu = `duplicate`). Le lot est ensuite
 * enregistré et, s'il est petit, traité tout de suite ; sinon mis en file pour `sync:process`.
 */
final class BatchService
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly BatchDecoder $decoder,
        private readonly BatchRepository $batches,
        private readonly BatchProcessor $processor,
        private readonly SyncSettings $settings,
        private readonly SyncClock $clock,
        private readonly Db $db,
    ) {
    }

    /** @return array<string, mixed> vue du lot (voir BatchView) */
    public function submit(Principal $principal, string $establishmentId, string $deviceId, string $batchId, string $signature, string $compressed): array
    {
        $tenantId = $principal->tenantId;
        $maxCompressed = $this->settings->maxCompressedBytes($tenantId, $establishmentId);
        if (strlen($compressed) > $maxCompressed) {
            throw BatchDecoder::tooLarge('Le lot compressé dépasse ' . $maxCompressed . ' octets.');
        }
        $device = $this->devices->authenticate($tenantId, $establishmentId, $deviceId, $batchId, $compressed, $signature);

        $decoded = $this->decoder->decode(
            $compressed,
            $this->settings->maxItems($tenantId, $establishmentId),
            $this->settings->maxUncompressedBytes($tenantId, $establishmentId),
        );

        $existing = $this->batches->findByBatchId($tenantId, $batchId);
        if ($existing === null) {
            try {
                $existing = $this->receive($principal, $establishmentId, $device, $batchId, $decoded);
            } catch (PDOException $e) {
                // Deux envois simultanés du même lot : le second perd la course sur l'index unique et devient un rejeu.
                $raced = $e->getCode() === '23000' ? $this->batches->findByBatchId($tenantId, $batchId) : null;
                if ($raced === null) {
                    throw $e;
                }

                return $this->replay($raced, $establishmentId, $deviceId, $decoded['hash']);
            }
            $this->devices->touch($tenantId, $establishmentId, $deviceId);

            return $this->view($existing, false);
        }
        $this->devices->touch($tenantId, $establishmentId, $deviceId);

        return $this->replay($existing, $establishmentId, $deviceId, $decoded['hash']);
    }

    /** @return array<string, mixed> vue du lot de cet établissement, telle qu'enregistrée (404 sinon) */
    public function get(string $tenantId, string $establishmentId, string $batchId): array
    {
        $batch = $this->batches->findByBatchId($tenantId, $batchId);
        if ($batch === null || $batch['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Lot introuvable.');
        }

        return $this->view($batch, false);
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function replay(array $existing, string $establishmentId, string $deviceId, string $hash): array
    {
        if ($existing['establishment_id'] !== $establishmentId || $existing['device_id'] !== $deviceId || $existing['body_hash'] !== $hash) {
            throw HttpException::validation(['Idempotency-Key' => ['Cet identifiant de lot a déjà servi pour un autre lot.']], 'Identifiant de lot déjà utilisé.');
        }

        return $this->view($existing, true);
    }

    /**
     * @param array<string, mixed> $device
     * @param array{hash: string, items: list<array{kind: string, client_uuid: string, payload: array<string, mixed>}>} $decoded
     * @return array<string, mixed> ligne du lot enregistré (puis traité si petit)
     */
    private function receive(Principal $principal, string $establishmentId, array $device, string $batchId, array $decoded): array
    {
        $tenantId = $principal->tenantId;
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $inline = count($decoded['items']) <= $this->settings->inlineMaxItems($tenantId, $establishmentId);

        $batch = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'device_id' => (string) $device['id'],
            'batch_id' => $batchId,
            'body_hash' => $decoded['hash'],
            'submitted_by' => $principal->userId,
            'status' => $inline ? BatchRepository::PROCESSING : BatchRepository::QUEUED,
            'item_count' => count($decoded['items']),
            'received_at' => $now,
            'locked_at' => $inline ? $now : null,
            'processed_at' => null,
        ];
        $this->db->transaction(function () use ($batch, $decoded, $tenantId, $establishmentId, $now): void {
            $this->batches->insertBatch($batch);
            foreach ($decoded['items'] as $number => $item) {
                $this->batches->insertItem([
                    'id' => (string) new Ulid(),
                    'tenant_id' => $tenantId,
                    'establishment_id' => $establishmentId,
                    'batch_ref' => $batch['id'],
                    'item_no' => $number,
                    'kind' => $item['kind'],
                    'client_uuid' => $item['client_uuid'],
                    'status' => BatchRepository::PENDING,
                    'result_code' => null,
                    'payload' => json_encode($item['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'result' => null,
                    'created_at' => $now,
                    'processed_at' => null,
                ]);
            }
        });
        if ($inline) {
            $this->processor->process($batch);
        }

        return $this->batches->findByBatchId($tenantId, $batchId) ?? $batch;
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function view(array $batch, bool $replayed): array
    {
        return BatchView::of($batch, $this->batches->items((string) $batch['tenant_id'], (string) $batch['id']), $replayed);
    }
}
