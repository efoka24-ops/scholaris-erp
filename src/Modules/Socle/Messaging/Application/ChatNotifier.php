<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Messaging\Contracts\NotificationChannelPreferences;
use Scholaris\Modules\Socle\Messaging\Domain\Channel;
use Scholaris\Modules\Socle\Messaging\Infrastructure\ChatRepository;
use Throwable;

/**
 * Notification d'un nouveau message de la messagerie interne (modèle `chat_new_message`, jamais le texte du message) :
 * toujours dans l'application ; en plus par e-mail ou SMS selon les réglages personnels du destinataire, au plus une fois
 * par conversation, par canal et par heure. Un échec de notification ne bloque jamais le message.
 */
final class ChatNotifier
{
    public const TEMPLATE = 'chat_new_message';
    public const THROTTLE_SECONDS = 3600;

    public function __construct(
        private readonly Messenger $messenger,
        private readonly NotificationChannelPreferences $preferences,
        private readonly ChatRepository $chat,
        private readonly MessagingClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param list<string> $personIds destinataires (l'auteur du message en est déjà exclu) */
    public function notify(string $tenantId, string $establishmentId, string $conversationId, array $personIds): void
    {
        $now = $this->clock->now();
        $since = $this->clock->iso($now->modify('-' . self::THROTTLE_SECONDS . ' seconds'));
        foreach (array_values(array_unique($personIds)) as $personId) {
            try {
                $extra = [];
                foreach ($this->preferences->extraChannelsFor($tenantId, $personId) as $channel) {
                    if (in_array($channel, [Channel::EMAIL, Channel::SMS], true)
                        && !$this->chat->notifiedSince($tenantId, $conversationId, $personId, $channel, $since)) {
                        $extra[] = $channel;
                    }
                }
                $result = $this->messenger->send($tenantId, $establishmentId, $personId, self::TEMPLATE, [], [Channel::PUSH, ...array_values(array_unique($extra))]);
                if ($result->status !== DeliveryResult::BLOCKED) {
                    foreach ($extra as $channel) {
                        $this->chat->markNotified($tenantId, $establishmentId, $conversationId, $personId, $channel, $this->clock->iso($now));
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warning('Notification de messagerie non envoyée', ['conversation' => $conversationId, 'error' => $e::class]);
            }
        }
    }
}
