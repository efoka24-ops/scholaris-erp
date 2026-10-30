<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Messaging\Domain\Audience;
use Scholaris\Modules\Socle\Messaging\Domain\Channel;
use Scholaris\Modules\Socle\Messaging\Domain\Recipient;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateException;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;
use Scholaris\Modules\Socle\Messaging\Infrastructure\TemplateRepository;

/**
 * Envoi à une personne depuis un modèle (appelé par Billing, Attendance, Identity...). Chaque appel est tracé :
 * un message, une livraison par canal, avec le motif quand le message est bloqué ou mis en file.
 */
final class MessengerImpl implements Messenger
{
    public function __construct(
        private readonly Store $store,
        private readonly TemplateRepository $templates,
        private readonly MessageService $messages,
        private readonly DeliveryProcessor $processor,
        private readonly MessagingClock $clock,
    ) {
    }

    public function send(
        string $tenantId,
        string $establishmentId,
        string $personId,
        string $templateKey,
        array $variables = [],
        array $channels = [],
        bool $urgent = false,
    ): DeliveryResult {
        try {
            TemplateRenderer::assertVariablesAllowed($variables);
        } catch (TemplateException $e) {
            return new DeliveryResult(DeliveryResult::BLOCKED, null, $e->reason);
        }
        $channels = $channels === [] ? $this->templates->channelsOf($tenantId, $establishmentId, $templateKey) : array_values(array_unique($channels));
        if ($channels === [] || array_diff($channels, Channel::all()) !== []) {
            return new DeliveryResult(DeliveryResult::BLOCKED, null, 'template_missing');
        }

        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $this->store->insert('messages', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'created_by' => null,
            'template_key' => $templateKey,
            'body' => null,
            'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
            'variables' => json_encode($variables, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'audience' => json_encode(Audience::of(Audience::PERSON, $personId)->toArray(), JSON_THROW_ON_ERROR),
            'urgent' => $urgent ? 1 : 0,
            'status' => MessageService::COMPLETED,
            'scheduled_at' => null,
            'recipients_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $message = $this->messages->message($tenantId, $establishmentId, $id);

        $statuses = [];
        $reasons = [];
        foreach ($this->messages->spawn($message, [new Recipient($personId)], $channels) as $deliveryId) {
            $delivery = $this->store->find('message_deliveries', $tenantId, $establishmentId, $deliveryId);
            if ($delivery === null) {
                continue;
            }
            $outcome = $this->processor->process($delivery, $message);
            $statuses[] = $outcome['status'];
            if ($outcome['reason'] !== null) {
                $reasons[] = $outcome['reason'];
            }
        }

        if (array_intersect($statuses, [DeliveryProcessor::SENT, DeliveryProcessor::DELIVERED]) !== []) {
            return new DeliveryResult(DeliveryResult::SENT, $id, $reasons[0] ?? null);
        }
        if (in_array(DeliveryProcessor::QUEUED, $statuses, true)) {
            $this->store->update('messages', $tenantId, $establishmentId, $id, ['status' => MessageService::SCHEDULED, 'updated_at' => $this->clock->iso()]);

            return new DeliveryResult(DeliveryResult::QUEUED, $id, 'outside_window');
        }

        return new DeliveryResult(DeliveryResult::BLOCKED, $id, $reasons[0] ?? 'blocked');
    }
}
