<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsStatusLookup;
use Scholaris\Modules\Socle\Messaging\Infrastructure\DeliveryRepository;
use Throwable;

/**
 * Suivi d'acheminement des SMS : relit chez le fournisseur (par la référence mémorisée à l'envoi, ex. « teeeotp-<id> ») l'état
 * des SMS « sent » récents et les passe à « delivered » ou « failed ». Un SMS « failed » rejoint la file de reprise (SendQueue).
 * Sans fournisseur capable de suivre l'état, ne fait rien. Les journaux ne contiennent ni numéro ni texte.
 */
final class SmsStatusSync
{
    public const FAILED_REASON = 'provider_failed';
    /** Au-delà, un SMS resté « sent » n'est plus suivi (le fournisseur n'a plus d'état utile). */
    public const TRACK_DAYS = 3;
    /** Interruption après autant d'erreurs de suite (fournisseur injoignable) : inutile d'insister. */
    private const MAX_CONSECUTIVE_ERRORS = 3;

    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly SmsStatusLookup $lookup,
        private readonly MessagingClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{checked: int, delivered: int, failed: int, pending: int, errors: int} */
    public function run(int $limit = 200): array
    {
        $result = ['checked' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];
        $prefix = $this->lookup->referencePrefix();
        if ($prefix === null || $prefix === '') {
            return $result;
        }

        $now = $this->clock->now();
        $since = $this->clock->iso($now->modify('-' . self::TRACK_DAYS . ' days'));
        $at = $this->clock->iso($now);
        $consecutive = 0;
        foreach ($this->deliveries->sentSmsToTrack($prefix, $since, $limit) as $row) {
            ++$result['checked'];
            try {
                $state = $this->lookup->lookup((string) $row['provider_reference']);
                $consecutive = 0;
            } catch (Throwable $e) {
                ++$result['errors'];
                $this->logger->warning('Suivi SMS impossible', ['delivery' => $row['id'], 'error' => $e::class]);
                if (++$consecutive >= self::MAX_CONSECUTIVE_ERRORS) {
                    break;
                }
                continue;
            }

            if ($state === SmsStatusLookup::DELIVERED && $this->deliveries->resolveSent((string) $row['tenant_id'], (string) $row['id'], 'delivered', null, $at)) {
                ++$result['delivered'];
            } elseif ($state === SmsStatusLookup::FAILED && $this->deliveries->resolveSent((string) $row['tenant_id'], (string) $row['id'], 'failed', self::FAILED_REASON, $at)) {
                ++$result['failed'];
            } else {
                ++$result['pending'];
            }
        }
        $this->logger->info('Suivi des SMS', $result);

        return $result;
    }
}
