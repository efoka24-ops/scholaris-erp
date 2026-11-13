<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Domain\Audience;
use Scholaris\Modules\Socle\Messaging\Domain\Channel;
use Scholaris\Modules\Socle\Messaging\Domain\Recipient;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateException;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Messaging\Infrastructure\DeliveryRepository;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;
use Scholaris\Modules\Socle\Messaging\Infrastructure\TemplateRepository;

/**
 * Cycle d'un message : brouillon, envoi (une livraison par destinataire et par canal), traitement par lots.
 * Faute de file `jobs`, l'envoi traite les livraisons dues dans la requête par lots ; processPending() est
 * l'entrée d'un futur worker (livraisons programmées ou mises en file hors plage horaire).
 */
final class MessageService
{
    public const BATCH = 100;
    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const COMPLETED = 'completed';

    public function __construct(
        private readonly Store $store,
        private readonly DeliveryRepository $deliveries,
        private readonly TemplateRepository $templates,
        private readonly AudienceResolver $resolver,
        private readonly DeliveryProcessor $processor,
        private readonly MessagingClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data template_key|body, channels, variables, audience (Audience), urgent, scheduled_at
     * @return array<string, mixed> le message créé (brouillon)
     */
    public function createDraft(string $tenantId, string $establishmentId, ?string $actorId, array $data): array
    {
        /** @var Audience $audience */
        $audience = $data['audience'];
        $key = $data['template_key'] ?? null;
        $body = $data['body'] ?? null;
        $variables = (array) ($data['variables'] ?? []);
        $channels = array_values(array_unique((array) ($data['channels'] ?? [])));

        $errors = [];
        if (($key === null) === ($body === null)) {
            $errors['template_key'] = ['Indiquez soit un modèle (template_key), soit un texte libre (body).'];
        }
        try {
            TemplateRenderer::assertVariablesAllowed($variables);
            if (is_string($body)) {
                TemplateRenderer::assertBodyAllowed($body);
            }
        } catch (TemplateException $e) {
            $errors['variables'] = [$e->getMessage()];
        }
        if (is_string($key) && $this->templates->channelsOf($tenantId, $establishmentId, $key) === []) {
            $errors['template_key'] = ['Aucun modèle ne porte cette clé.'];
        }
        if ($body !== null && $channels === []) {
            $errors['channels'] = ['Un texte libre exige au moins un canal.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $this->store->insert('messages', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'created_by' => $actorId,
            'template_key' => $key,
            'body' => $body,
            'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
            'variables' => json_encode($variables, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'audience' => json_encode($audience->toArray(), JSON_THROW_ON_ERROR),
            'urgent' => ($data['urgent'] ?? false) === true ? 1 : 0,
            'status' => self::DRAFT,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'recipients_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->message($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function message(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->find('messages', $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Message introuvable.');
    }

    /** @return array<string, mixed> le message après envoi */
    public function send(string $tenantId, string $establishmentId, string $id): array
    {
        $message = $this->message($tenantId, $establishmentId, $id);
        if ($message['status'] !== self::DRAFT) {
            throw HttpException::conflict('Ce message a déjà été envoyé.');
        }

        $audience = Audience::fromArray((array) json_decode((string) $message['audience'], true));
        $recipients = $this->resolver->resolve($tenantId, $establishmentId, $audience);
        if ($recipients === []) {
            throw HttpException::validation(['audience' => ['Cette audience ne désigne aucun destinataire.']], 'Aucun destinataire.');
        }
        $channels = $this->channelsOf($message, $tenantId, $establishmentId);

        $notBefore = null;
        $future = false;
        if (is_string($message['scheduled_at']) && $message['scheduled_at'] !== '') {
            $when = new DateTimeImmutable($message['scheduled_at']);
            $future = $when > $this->clock->now();
            $notBefore = $future ? $this->clock->iso($when) : null;
        }

        $this->spawn($message, $recipients, $channels, $notBefore);
        $this->store->update('messages', $tenantId, $establishmentId, $id, [
            'status' => $future ? self::SCHEDULED : self::COMPLETED,
            'recipients_count' => count($recipients),
            'updated_at' => $this->clock->iso(),
        ]);
        if (!$future) {
            $this->drain($tenantId, $id);
        }

        return $this->message($tenantId, $establishmentId, $id);
    }

    /**
     * Crée les livraisons (statut pending, ou queued si une heure est imposée).
     *
     * @param array<string, mixed> $message
     * @param list<Recipient> $recipients
     * @param list<string> $channels
     * @return list<string> identifiants des livraisons
     */
    public function spawn(array $message, array $recipients, array $channels, ?string $notBefore = null): array
    {
        $ids = [];
        $now = $this->clock->iso();
        foreach ($recipients as $recipient) {
            foreach ($channels as $channel) {
                $id = (string) new Ulid();
                $this->store->insert('message_deliveries', [
                    'id' => $id,
                    'tenant_id' => (string) $message['tenant_id'],
                    'establishment_id' => (string) $message['establishment_id'],
                    'message_id' => (string) $message['id'],
                    'recipient_id' => $recipient->personId,
                    'channel' => $channel,
                    'status' => $notBefore === null ? 'pending' : 'queued',
                    'reason' => $notBefore === null ? null : 'scheduled',
                    'variables' => $recipient->variables === [] ? null : json_encode($recipient->variables, JSON_THROW_ON_ERROR),
                    'not_before' => $notBefore,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Traite, par lots, toutes les livraisons dues d'un message. */
    public function drain(string $tenantId, string $messageId): int
    {
        $processed = 0;
        do {
            $batch = $this->deliveries->due($this->clock->iso(), self::BATCH, $tenantId, $messageId);
            $this->run($batch);
            $processed += count($batch);
            // Les livraisons remises en file (hors plage) ne sont plus dues : la boucle s'arrête d'elle-même.
        } while ($batch !== []);

        return $processed;
    }

    /**
     * Point d'entrée d'un futur worker : traite jusqu'à $limit livraisons dues, tous clients confondus.
     *
     * @return int nombre de livraisons traitées
     */
    public function processPending(int $limit = self::BATCH): int
    {
        $batch = $this->deliveries->due($this->clock->iso(), $limit);
        $this->run($batch);

        return count($batch);
    }

    /** @param list<array<string, mixed>> $batch */
    private function run(array $batch): void
    {
        /** @var array<string, array<string, mixed>|null> $messages */
        $messages = [];
        $touched = [];
        foreach ($batch as $delivery) {
            $tenantId = (string) $delivery['tenant_id'];
            $establishmentId = (string) $delivery['establishment_id'];
            $key = $tenantId . '|' . $establishmentId . '|' . $delivery['message_id'];
            $messages[$key] ??= $this->store->find('messages', $tenantId, $establishmentId, (string) $delivery['message_id']);
            if ($messages[$key] === null) {
                $this->deliveries->update($tenantId, (string) $delivery['id'], ['status' => 'blocked', 'reason' => 'message_missing', 'updated_at' => $this->clock->iso()]);
                continue;
            }
            $this->processor->process($delivery, $messages[$key]);
            $touched[$key] = [$tenantId, $establishmentId, (string) $delivery['message_id']];
        }

        foreach ($touched as [$tenantId, $establishmentId, $messageId]) {
            $counts = $this->deliveries->countsByStatus($tenantId, $establishmentId, $messageId);
            $waiting = ($counts['pending'] ?? 0) + ($counts['queued'] ?? 0);
            $this->store->update('messages', $tenantId, $establishmentId, $messageId, ['status' => $waiting === 0 ? self::COMPLETED : self::SCHEDULED, 'updated_at' => $this->clock->iso()]);
        }
    }

    /**
     * @param array<string, mixed> $message
     * @return list<string>
     */
    private function channelsOf(array $message, string $tenantId, string $establishmentId): array
    {
        $channels = array_values(array_filter((array) json_decode((string) $message['channels'], true), 'is_string'));
        if ($channels === [] && is_string($message['template_key'])) {
            $channels = $this->templates->channelsOf($tenantId, $establishmentId, $message['template_key']);
        }

        return array_values(array_intersect(Channel::all(), $channels));
    }
}
