<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Documents\Infrastructure\JobRepository;
use Throwable;

/**
 * File de génération en lot. Une tâche = un lot ; le worker (commande `documents:process-jobs`) la traite par
 * tranches de documents : la mémoire reste bornée quelle que soit la taille du lot, et la progression est
 * enregistrée après chaque tranche. Reprise : une tâche « en cours » dont le verrou n'a plus été rafraîchi
 * depuis `queue.stale_after` secondes est reprise ; les lignes déjà traitées ne sont jamais refaites.
 */
final class DocumentJobService
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly BatchService $batches,
        private readonly DocumentClock $clock,
        private readonly Config $config,
    ) {
    }

    /**
     * Traite les tâches disponibles (au plus `$maxJobs`).
     *
     * @return array{jobs: int, done: int, failed: int, chunks: int}
     */
    public function work(int $maxJobs = 10, ?int $chunk = null): array
    {
        $chunk = max(1, $chunk ?? (int) $this->config->get('documents.queue.chunk', BatchService::CHUNK));
        $maxAttempts = max(1, (int) $this->config->get('documents.queue.max_attempts', 3));
        $staleAfter = max(1, (int) $this->config->get('documents.queue.stale_after', 600));
        $stats = ['jobs' => 0, 'done' => 0, 'failed' => 0, 'chunks' => 0];

        $seen = [];
        while ($stats['jobs'] < $maxJobs) {
            $stale = $this->clock->moment()->modify('-' . $staleAfter . ' seconds')->format(DATE_ATOM);
            $job = $this->jobs->candidate($stale);
            if ($job === null || isset($seen[(string) $job['id']])) {
                break; // une tâche remise en file attend le prochain passage du worker
            }
            $tenant = (string) $job['tenant_id'];
            $id = (string) $job['id'];
            $seen[$id] = true;
            if (!$this->jobs->claim($tenant, $id, $stale, $this->clock->now())) {
                continue;
            }
            $stats['jobs']++;
            $attempts = (int) $job['attempts'] + 1;
            if ($attempts > $maxAttempts) {
                // Abandonnée trop souvent (arrêts répétés du worker) : en échec, au lieu d'être rejouée sans fin.
                $this->jobs->finish($tenant, $id, 'failed', 'Nombre maximal de tentatives atteint.', $this->clock->now());
                $stats['failed']++;
                continue;
            }

            try {
                $stats['chunks'] += $this->run($tenant, (string) $job['batch_id'], $id, $chunk);
                $this->jobs->finish($tenant, $id, 'done', null, $this->clock->now());
                $stats['done']++;
            } catch (Throwable $e) {
                $retry = $attempts < $maxAttempts;
                $this->jobs->finish($tenant, $id, $retry ? 'queued' : 'failed', mb_substr($e->getMessage(), 0, 255), $this->clock->now());
                $stats['failed'] += $retry ? 0 : 1;
            }
        }

        return $stats;
    }

    /** @return int nombre de tranches traitées */
    private function run(string $tenantId, string $batchId, string $jobId, int $chunk): int
    {
        $chunks = 0;
        while (true) {
            $batch = $this->batches->process($tenantId, $batchId, $chunk);
            $chunks++;
            $this->jobs->heartbeat($tenantId, $jobId, $this->clock->now());
            if ($batch['status'] === 'done') {
                return $chunks;
            }
            gc_collect_cycles();
        }
    }
}
