<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use JsonException;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;
use Scholaris\Modules\Socle\Sync\Infrastructure\BatchRepository;
use Throwable;

/**
 * Traite les éléments en attente d'un lot, dans l'ordre : écarte les rejeux (un élément déjà appliqué n'est jamais appliqué
 * deux fois), borne le nombre de lignes, confie l'élément à son gestionnaire `sync.handlers.<kind>` et enregistre le résultat.
 * Un conflit, un refus ou une erreur d'un élément n'arrête pas les suivants. L'appelant place le contexte de client du noyau.
 */
final class BatchProcessor
{
    public function __construct(
        private readonly BatchRepository $batches,
        private readonly HandlerRegistry $handlers,
        private readonly SyncSettings $settings,
        private readonly SyncClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $batch ligne de sync_batches */
    public function process(array $batch): void
    {
        $tenantId = (string) $batch['tenant_id'];
        $establishmentId = (string) $batch['establishment_id'];
        $context = new BatchContext(new Principal((string) $batch['submitted_by'], $tenantId), $establishmentId, (string) $batch['device_id'], (string) $batch['batch_id']);

        foreach ($this->batches->items($tenantId, (string) $batch['id']) as $row) {
            if ($row['status'] !== BatchRepository::PENDING) {
                continue;
            }
            [$result, $original] = $this->handle($row, $context);
            $stored = array_filter(
                ['message' => $result->message, 'data' => $result->data === [] ? null : $result->data, 'original_status' => $original],
                static fn (mixed $value): bool => $value !== null,
            );
            $this->batches->finishItem(
                $tenantId,
                (string) $row['id'],
                $result->status,
                $result->code,
                json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->clock->now(),
            );
        }
        $this->batches->finishBatch($tenantId, (string) $batch['id'], $this->clock->now());
    }

    /**
     * @param array<string, mixed> $row ligne de sync_items
     * @return array{0: ItemResult, 1: string|null} résultat, et statut d'origine quand l'élément est un doublon
     */
    private function handle(array $row, BatchContext $context): array
    {
        $kind = (string) $row['kind'];
        $clientUuid = (string) $row['client_uuid'];

        $done = $this->batches->findDone($context->tenantId(), $context->establishmentId, $kind, $clientUuid, (string) $row['id']);
        if ($done !== null) {
            return [ItemResult::duplicate(), $this->originalStatus($done)];
        }

        return [$this->invoke($row, $context), null];
    }

    /** @param array<string, mixed> $row ligne de sync_items */
    private function invoke(array $row, BatchContext $context): ItemResult
    {
        $kind = (string) $row['kind'];
        $clientUuid = (string) $row['client_uuid'];

        $handler = $this->handlers->for($kind);
        if ($handler === null) {
            return ItemResult::rejected('Aucun gestionnaire pour le type « ' . $kind . ' ».');
        }
        try {
            $payload = json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ItemResult::rejected('Charge utile illisible.');
        }
        if (!is_array($payload)) {
            return ItemResult::rejected('Charge utile illisible.');
        }
        $maxRows = $this->settings->maxRowsPerItem($context->tenantId(), $context->establishmentId);
        if (self::rows($payload) > $maxRows) {
            return ItemResult::rejected('Trop de lignes dans cet élément : ' . $maxRows . ' au plus.');
        }

        try {
            /** @var array<string, mixed> $payload */
            return $handler->handle(new BatchItem($kind, $clientUuid, $payload), $context);
        } catch (HttpException $e) {
            return $e->status === 409
                ? ItemResult::conflict($e->getMessage())
                : ItemResult::rejected($e->getMessage(), $e->status, $e->errors);
        } catch (Throwable $e) {
            // Ni message ni charge utile : ils peuvent contenir des données d'élèves.
            $this->logger->error('Élément de lot en erreur.', ['kind' => $kind, 'batch' => $context->batchId, 'item' => $row['item_no'], 'exception' => $e::class]);

            return ItemResult::rejected('Erreur interne de traitement de l\'élément.', 500);
        }
    }

    /**
     * Lignes d'une charge utile : taille de la plus grande liste de premier niveau.
     *
     * @param array<mixed> $payload
     */
    private static function rows(array $payload): int
    {
        $rows = 0;
        foreach ($payload as $value) {
            if (is_array($value) && array_is_list($value)) {
                $rows = max($rows, count($value));
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $done */
    private function originalStatus(array $done): string
    {
        $stored = json_decode((string) ($done['result'] ?? ''), true);
        $original = is_array($stored) ? ($stored['original_status'] ?? null) : null;

        return is_string($original) ? $original : (string) $done['status'];
    }
}
