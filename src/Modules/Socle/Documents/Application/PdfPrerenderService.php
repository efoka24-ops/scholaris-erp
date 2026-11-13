<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Documents\Infrastructure\DocumentRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\PdfRenderQueue;
use Scholaris\Modules\Socle\Documents\Infrastructure\PdfStore;
use Throwable;

/**
 * Pré-rendu des PDF de bulletins (C-118, NF-S2) : chaque bulletin valide pas encore rendu est pris (verrou par document),
 * rendu dans son profil (léger par défaut) puis stocké ; le portail, l'aperçu et les liens de téléchargement servent ensuite
 * le fichier sans nouveau rendu. Plusieurs processus `documents:process-jobs` se partagent la file sans doublon.
 */
final class PdfPrerenderService
{
    private const PICK = 20;

    public function __construct(
        private readonly PdfRenderQueue $queue,
        private readonly DocumentRepository $documents,
        private readonly DocumentService $service,
        private readonly PdfStore $store,
        private readonly PdfProfiles $profiles,
        private readonly DocumentClock $clock,
        private readonly Config $config,
    ) {
    }

    /**
     * Rend au plus `$limit` bulletins, avant `$deadline` (horodatage microtime) s'il est fourni.
     *
     * @return array{rendered: int, failed: int}
     */
    public function work(int $limit, ?float $deadline = null): array
    {
        $staleAfter = max(1, (int) $this->config->get('documents.queue.stale_after', 600));
        $stats = ['rendered' => 0, 'failed' => 0];
        $seen = [];
        $open = static fn (): bool => $deadline === null || microtime(true) < $deadline;
        while ($stats['rendered'] + $stats['failed'] < $limit && $open()) {
            $stale = $this->clock->moment()->modify('-' . $staleAfter . ' seconds')->format(DATE_ATOM);
            $fresh = array_values(array_filter($this->queue->candidates($stale, self::PICK), static fn (array $c): bool => !isset($seen[$c['id']])));
            if ($fresh === []) {
                break;
            }
            foreach ($fresh as $candidate) {
                $seen[$candidate['id']] = true;
                if ($stats['rendered'] + $stats['failed'] >= $limit || !$open()) {
                    break;
                }
                if (!$this->queue->claim($candidate['tenant_id'], $candidate['id'], $stale, $this->clock->now())) {
                    continue; // pris par un autre processus
                }
                try {
                    $document = $this->documents->find($candidate['tenant_id'], $candidate['id'], $candidate['establishment_id']);
                    if ($document === null) {
                        continue;
                    }
                    $profile = $this->profiles->forCategory((string) $document['category']);
                    $this->store->put($candidate['tenant_id'], $candidate['id'], $profile, $this->service->pdf((string) $document['content'], $profile));
                    $this->queue->rendered($candidate['tenant_id'], $candidate['id'], $profile, $this->clock->now());
                    $stats['rendered']++;
                } catch (Throwable) {
                    // Verrou laissé : le document est repris après `stale_after`, sans boucler sur lui dans ce passage.
                    $stats['failed']++;
                }
                gc_collect_cycles();
            }
        }

        return $stats;
    }
}
