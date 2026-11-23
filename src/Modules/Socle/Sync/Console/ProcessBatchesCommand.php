<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Console;

use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Sync\Application\BatchProcessor;
use Scholaris\Modules\Socle\Sync\Application\SyncClock;
use Scholaris\Modules\Socle\Sync\Infrastructure\BatchRepository;

/**
 * `sync:process [--limit=N]` : traite les lots hors ligne mis en file (plus de `sync.batch.inline_max_items` éléments), à
 * planifier chaque minute par cron (pas de Redis). Chaque lot est pris par un seul processus (verrou en base) et traité dans son
 * client et son établissement, au nom de la personne qui l'a envoyé. Un lot « en cours » depuis plus de 10 minutes (processus
 * interrompu) est repris : les éléments déjà traités ne le sont pas deux fois. Clé de conteneur `console.command.sync:process`.
 */
final class ProcessBatchesCommand
{
    public const NAME = 'sync:process';
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 500;
    public const STALE_MINUTES = 10;

    public function __construct(
        private readonly BatchRepository $batches,
        private readonly BatchProcessor $processor,
        private readonly TenantContext $tenants,
        private readonly SyncClock $clock,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $limit = self::DEFAULT_LIMIT;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d{1,3})$/D', $arg, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= self::MAX_LIMIT) {
                $limit = (int) $m[1];
            } else {
                fwrite(STDERR, 'Argument inconnu ou invalide : ' . $arg . "\nUsage : artisan sync:process [--limit=N]  (N entre 1 et " . self::MAX_LIMIT . ")\n");

                return 2;
            }
        }

        $staleBefore = $this->clock->minutesAgo(self::STALE_MINUTES);
        $processed = 0;
        foreach ($this->batches->queued($staleBefore, $limit) as $batch) {
            $tenantId = (string) $batch['tenant_id'];
            if (!$this->batches->claim($tenantId, (string) $batch['id'], $this->clock->now(), $staleBefore)) {
                continue;
            }
            $this->tenants->set($tenantId, (string) $batch['establishment_id']);
            try {
                $this->processor->process($batch);
                ++$processed;
            } finally {
                $this->tenants->clear();
            }
        }
        echo sprintf("Synchronisation : %d lot(s) traité(s).\n", $processed);

        return 0;
    }
}
