<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;
use Scholaris\Modules\Socle\Sync\Infrastructure\BatchRepository;

/** Représentation d'un lot et de ses éléments rendue par l'API (aucune charge utile, jamais). */
final class BatchView
{
    /**
     * @param array<string, mixed> $batch ligne de sync_batches
     * @param list<array<string, mixed>> $items lignes de sync_items
     * @param bool $replayed vrai quand le lot est renvoyé : chaque élément déjà traité est alors rendu en `duplicate`
     * @return array{id: string, status: string, replayed: bool, device_id: string, received_at: string, processed_at: string|null, counts: array<string, int>, items: list<array<string, mixed>>}
     */
    public static function of(array $batch, array $items, bool $replayed): array
    {
        $counts = [ItemResult::APPLIED => 0, ItemResult::DUPLICATE => 0, ItemResult::CONFLICT => 0, ItemResult::REJECTED => 0, BatchRepository::PENDING => 0];
        $rendered = [];
        foreach ($items as $row) {
            $item = self::item($row, $replayed);
            ++$counts[(string) $item['status']];
            $rendered[] = $item;
        }

        return [
            'id' => (string) $batch['batch_id'],
            'status' => (string) $batch['status'],
            'replayed' => $replayed,
            'device_id' => (string) $batch['device_id'],
            'received_at' => (string) $batch['received_at'],
            'processed_at' => $batch['processed_at'] === null ? null : (string) $batch['processed_at'],
            'counts' => $counts,
            'items' => $rendered,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function item(array $row, bool $replayed): array
    {
        $status = (string) $row['status'];
        $stored = json_decode((string) ($row['result'] ?? ''), true);
        $stored = is_array($stored) ? $stored : [];
        $item = [
            'index' => (int) $row['item_no'],
            'kind' => (string) $row['kind'],
            'client_uuid' => (string) $row['client_uuid'],
            'status' => $status,
            'code' => $row['result_code'] === null ? null : (int) $row['result_code'],
        ];
        $original = $stored['original_status'] ?? null;
        if ($replayed && $status !== BatchRepository::PENDING) {
            $original = is_string($original) ? $original : $status;
            $item['status'] = ItemResult::DUPLICATE;
            $item['code'] = 200;
        }
        if (is_string($original)) {
            $item['original_status'] = $original;
        }
        if (isset($stored['message']) && !$replayed) {
            $item['message'] = $stored['message'];
        }
        if (isset($stored['data']) && is_array($stored['data']) && !$replayed) {
            $item['data'] = $stored['data'];
        }

        return $item;
    }
}
