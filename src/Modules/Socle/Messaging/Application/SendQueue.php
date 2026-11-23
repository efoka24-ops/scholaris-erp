<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use DateTimeImmutable;
use Scholaris\Modules\Socle\Messaging\Domain\RetryBackoff;
use Scholaris\Modules\Socle\Messaging\Infrastructure\DeliveryRepository;

/**
 * File d'envoi : remet en file les livraisons en échec dont l'attente (backoff exponentiel) est écoulée, abandonne
 * celles qui ont épuisé leurs tentatives, puis traite toutes les livraisons dues (programmées, hors plage horaire,
 * reprises). À lancer chaque minute par la commande queue:send (tâche planifiée du serveur).
 *
 * Limite assumée : si un fournisseur a accepté un message sans que sa réponse nous parvienne, la reprise peut le
 * renvoyer une seconde fois (l'échec n'est alors pas prouvé).
 */
final class SendQueue
{
    public const ABANDONED = 'retries_exhausted';

    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly MessageService $messages,
        private readonly MessagingClock $clock,
        private readonly RetryBackoff $backoff = new RetryBackoff(),
        private readonly int $maxAttempts = 5,
    ) {
    }

    /** @return array{abandoned: int, retried: int, processed: int} */
    public function run(int $limit = MessageService::BATCH): array
    {
        $limit = max(1, $limit);
        $now = $this->clock->now();
        $iso = $this->clock->iso($now);

        $abandoned = $this->deliveries->abandonExhausted($this->maxAttempts, self::ABANDONED, $iso);
        $retried = $this->requeueFailed($now, $limit);

        return ['abandoned' => $abandoned, 'retried' => $retried, 'processed' => $this->messages->processPending($limit)];
    }

    private function requeueFailed(DateTimeImmutable $now, int $limit): int
    {
        $retried = 0;
        foreach ($this->deliveries->failedBelow($this->maxAttempts, $limit * 10) as $delivery) {
            if ($retried >= $limit) {
                break;
            }
            $attempts = (int) $delivery['attempts'];
            $failedAt = new DateTimeImmutable((string) $delivery['updated_at']);
            $readyAt = $failedAt->modify('+' . $this->backoff->delaySeconds($attempts) . ' seconds');
            if ($readyAt > $now) {
                continue;
            }
            if ($this->deliveries->claimRetry((string) $delivery['tenant_id'], (string) $delivery['id'], $attempts, $this->clock->iso($now))) {
                ++$retried;
            }
        }

        return $retried;
    }
}
