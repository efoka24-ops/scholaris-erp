<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Notifications\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Notifications\Contracts\ReportCardNotices;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\EssentialMessenger;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/** NOT-05 : un SMS par bulletin et par garant, message essentiel selon le paramètre de l'établissement (défaut vrai). */
final class ReportCardNotifier implements ReportCardNotices
{
    public const NOT05 = 'NOT-05';
    public const TEMPLATE = 'sec.not05.report_card_ready';
    public const SEND_FAILED = 'send_failed';
    private const TABLE = 'se_notification_log';

    public function __construct(
        private readonly Messenger $messenger,
        private readonly EssentialMessenger $essential,
        private readonly SettingsReader $settings,
        private readonly PersonDirectory $people,
        private readonly Store $store,
        private readonly SecondaryClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reportCardReady(string $tenantId, string $establishmentId, string $documentId, string $learnerId, array $variables): array
    {
        // D-BUL : seule la valeur fausse désactive le message essentiel (absence de paramètre = essentiel).
        $essential = !in_array($this->settings->value($tenantId, $establishmentId, self::SETTING, true), [false, 0, '0', 'false'], true);
        $out = ['families' => 0, 'notified' => 0, 'already_notified' => 0, 'not_notified' => []];
        $seen = [];
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
            if (isset($seen[$guardian->id])) {
                continue;
            }
            $seen[$guardian->id] = true;
            ++$out['families'];
            $where = ['notification_code' => self::NOT05, 'event_ref' => $documentId, 'recipient_person_id' => $guardian->id];
            if ($this->store->count(self::TABLE, $tenantId, $establishmentId, $where) > 0) {
                ++$out['already_notified'];

                continue;
            }
            try {
                $result = $essential
                    ? $this->essential->sendEssential($tenantId, $establishmentId, $guardian->id, self::TEMPLATE, $variables, ['sms'])
                    : $this->messenger->send($tenantId, $establishmentId, $guardian->id, self::TEMPLATE, $variables, ['sms']);
            } catch (Throwable $e) {
                $this->logger->warning('Notification NOT-05 non envoyée : ' . $e->getMessage(), ['document' => $documentId]);
                $out['not_notified'][] = self::SEND_FAILED;

                continue;
            }
            if ($result->status === DeliveryResult::BLOCKED) {
                $out['not_notified'][] = $result->reason ?? 'blocked';

                continue;
            }
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, $where + [
                'id' => (string) new Ulid(), 'template_key' => self::TEMPLATE, 'delivery_status' => $result->status, 'created_at' => $this->clock->now(),
            ]);
            ++$out['notified'];
        }

        return $out;
    }
}
