<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Notifications\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Apps\Higher\Notifications\Domain\NotificationCatalog;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Envoi des notifications NOT-01 à NOT-11 par le Messenger du socle (consentement et plages horaires appliqués par lui),
 * destinataires selon le catalogue, journal hi_notification_log et dédoublonnage par clé d'événement et destinataire.
 */
final class Notifier implements HigherNotifier
{
    public const GUARANTORS_SETTING = 'higher.notifications.reminders_to_guarantors';
    public const DUPLICATE = 'duplicate';
    public const FAILED = 'failed';

    public function __construct(
        private readonly Messenger $messenger,
        private readonly PersonDirectory $people,
        private readonly ConsentChecker $consents,
        private readonly SettingsReader $settings,
        private readonly ConnectionFactory $connections,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(string $tenantId, string $establishmentId, string $code, string $personId, array $variables = [], ?string $dedupeKey = null): array
    {
        $entry = NotificationCatalog::find($code) ?? throw new \InvalidArgumentException('Notification inconnue : ' . $code);
        $results = [];
        foreach ($this->recipients($tenantId, $establishmentId, $code, $entry['recipients'], $personId) as $recipientId) {
            if ($dedupeKey !== null && $this->alreadySent($tenantId, $establishmentId, $dedupeKey, $recipientId)) {
                $results[] = ['recipient_id' => $recipientId, 'status' => self::DUPLICATE, 'reason' => null];
                continue;
            }
            try {
                $delivery = $this->messenger->send($tenantId, $establishmentId, $recipientId, $entry['key'], $variables, $entry['channels']);
                [$status, $reason, $messageId] = [$delivery->status, $delivery->reason, $delivery->messageId];
            } catch (Throwable $e) {
                $this->logger->warning('Notification ' . $code . ' non envoyée : ' . $e->getMessage());
                [$status, $reason, $messageId] = [self::FAILED, 'error', null];
            }
            $this->log($tenantId, $establishmentId, $code, $entry['key'], $personId, $recipientId, $dedupeKey, $status, $reason, $messageId);
            $results[] = ['recipient_id' => $recipientId, 'status' => $status, 'reason' => $reason];
        }

        return $results;
    }

    /**
     * @param list<string> $kinds
     * @return list<string>
     */
    private function recipients(string $tenantId, string $establishmentId, string $code, array $kinds, string $personId): array
    {
        $ids = [];
        if (in_array(NotificationCatalog::SUBJECT, $kinds, true)) {
            $ids[] = $personId;
        }
        $guarantors = in_array(NotificationCatalog::GUARANTORS, $kinds, true)
            || (in_array($code, ['NOT-08', 'NOT-09'], true) && $this->settings->value($tenantId, $establishmentId, self::GUARANTORS_SETTING, false) === true);
        $withConsent = in_array(NotificationCatalog::GUARANTORS_WITH_CONSENT, $kinds, true)
            && $this->consents->hasConsent($tenantId, $personId, ConsentChecker::PARENT_ACCESS);
        if ($guarantors || $withConsent) {
            foreach ($this->people->guarantorsOf($tenantId, $personId) as $guarantor) {
                $ids[] = $guarantor->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function alreadySent(string $tenantId, string $establishmentId, string $dedupeKey, string $recipientId): bool
    {
        $statement = $this->connections->pdo()->prepare('SELECT 1 FROM hi_notification_log WHERE tenant_id = ? AND establishment_id = ? AND dedupe_key = ? AND recipient_id = ?');
        $statement->execute([$tenantId, $establishmentId, $dedupeKey, $recipientId]);

        return $statement->fetchColumn() !== false;
    }

    private function log(string $tenantId, string $establishmentId, string $code, string $key, string $personId, string $recipientId, ?string $dedupeKey, string $status, ?string $reason, ?string $messageId): void
    {
        $now = (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone('Africa/Douala'))->format(DATE_ATOM);
        $this->connections->pdo()->prepare(
            'INSERT INTO hi_notification_log (id, tenant_id, establishment_id, notification_code, template_key, person_id, recipient_id, dedupe_key, delivery_status, reason, message_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([(string) new Ulid(), $tenantId, $establishmentId, $code, $key, $personId, $recipientId, $dedupeKey, $status, $reason !== null ? mb_substr($reason, 0, 255) : null, $messageId, $now]);
    }

    /** @return list<array<string, mixed>> journal d'une personne (ou de tout l'établissement), du plus récent au plus ancien */
    public function journal(string $tenantId, string $establishmentId, ?string $personId, int $limit = 200): array
    {
        $sql = 'SELECT notification_code, template_key, person_id, recipient_id, dedupe_key, delivery_status, reason, created_at FROM hi_notification_log WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        if ($personId !== null) {
            $sql .= ' AND person_id = ?';
            $params[] = $personId;
        }
        $statement = $this->connections->pdo()->prepare($sql . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit)));
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
