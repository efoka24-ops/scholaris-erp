<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Contracts\SupportDesk;
use Scholaris\Modules\Socle\Messaging\Infrastructure\ChatRepository;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;
use Scholaris\Modules\Socle\Messaging\Infrastructure\SupportRepository;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Conversations « Support SCHOLARIS » entre des administrateurs d'un établissement (participants, rôle client_admin) et le
 * support de la plateforme (jamais participant : ses messages portent sender_side = platform et son identité n'est pas
 * montrée au client). Statut côté support : open (le client attend), pending (le support attend le client), resolved.
 * Le support ne lit que ces conversations (SupportRepository impose la nature « support »).
 */
final class SupportService implements SupportDesk
{
    /** Auteur affiché d'une conversation ouverte par le support (l'agent est tracé dans le journal de la plateforme). */
    public const CREATED_BY_SUPPORT = 'support';
    public const MAX_RECIPIENTS = 20;

    public function __construct(
        private readonly Store $store,
        private readonly ChatRepository $chat,
        private readonly SupportRepository $support,
        private readonly ChatService $conversations,
        private readonly PersonDirectory $persons,
        private readonly EstablishmentDirectory $establishments,
        private readonly MessagingClock $clock,
    ) {
    }

    /**
     * Ouverture par un administrateur de l'établissement (permission messaging.support vérifiée par l'appelant).
     *
     * @return array<string, mixed> la conversation créée
     */
    public function openFromClient(string $tenantId, string $establishmentId, string $actorPersonId, string $subject, string $body): array
    {
        $id = $this->create($tenantId, $establishmentId, [$actorPersonId], $actorPersonId, self::OPEN, $subject, static function (ChatService $chat, string $now, string $id) use ($tenantId, $establishmentId, $actorPersonId, $body): string {
            return $chat->insertMessage($tenantId, $establishmentId, $id, $actorPersonId, $body, [], $now);
        });

        return $this->conversations->conversation($tenantId, $establishmentId, $id);
    }

    public function openToClient(string $tenantId, string $establishmentId, array $recipientPersonIds, string $platformUserId, string $subject, string $body): array
    {
        if ($this->establishments->find($tenantId, $establishmentId) === null) {
            throw HttpException::validation(['establishment_id' => ['Établissement inconnu pour ce client.']]);
        }
        $recipients = array_values(array_unique($recipientPersonIds));
        $known = array_map(static fn ($p): string => $p->id, $this->persons->findMany($tenantId, $recipients));
        $recipients = array_values(array_intersect($recipients, $known));
        if ($recipients === []) {
            throw HttpException::validation(['tenant_id' => ['Ce client n\'a aucun administrateur actif à joindre.']]);
        }
        $recipients = array_slice($recipients, 0, self::MAX_RECIPIENTS);
        $id = $this->create($tenantId, $establishmentId, $recipients, self::CREATED_BY_SUPPORT, self::PENDING, $subject, static function (ChatService $chat, string $now, string $id) use ($tenantId, $establishmentId, $platformUserId, $body): string {
            return $chat->insertMessage($tenantId, $establishmentId, $id, $platformUserId, $body, [], $now, SupportRepository::PLATFORM_SIDE);
        }, true);
        $this->conversations->notify($tenantId, $establishmentId, $id, null);

        return $this->conversation($id);
    }

    public function inbox(array $filters, ?string $cursor, int $perPage): array
    {
        if (isset($filters['status']) && !in_array($filters['status'], self::STATUSES, true)) {
            throw HttpException::validation(['filter[status]' => ['Valeurs possibles : ' . implode(', ', self::STATUSES) . '.']]);
        }
        [$rows, $next] = $this->support->page($filters, $cursor, $perPage);

        return ['items' => array_map(fn (array $row): array => $this->present($row), $rows), 'next_cursor' => $next];
    }

    public function conversation(string $id): array
    {
        $row = $this->find($id);

        return $this->present($row) + ['participants' => $this->conversations->participants((string) $row['tenant_id'], $id)];
    }

    public function messages(string $id, ?string $cursor, int $perPage): array
    {
        $row = $this->find($id);
        $tenantId = (string) $row['tenant_id'];
        $after = $cursor !== null && preg_match('/^[0-9A-Za-z]{26}$/', $cursor) === 1 ? $cursor : null;
        [$rows, $next] = $this->support->messages($tenantId, $id, $after, $perPage);
        if ($rows !== [] && $after === null) {
            $this->support->markRead($tenantId, $id, (string) $rows[0]['id']);
        }

        return ['items' => array_map(self::presentMessage(...), $rows), 'next_cursor' => $next];
    }

    public function reply(string $id, string $platformUserId, string $body): array
    {
        $row = $this->find($id);
        if ($row['status'] !== ChatService::OPEN) {
            throw HttpException::conflict('Cette conversation est archivée.');
        }
        $tenantId = (string) $row['tenant_id'];
        $establishmentId = (string) $row['establishment_id'];
        $now = $this->clock->iso();
        $messageId = $this->conversations->insertMessage($tenantId, $establishmentId, $id, $platformUserId, $body, [], $now, SupportRepository::PLATFORM_SIDE);
        $this->support->update($tenantId, $id, ['last_message_at' => $now, 'updated_at' => $now, 'support_status' => self::PENDING]);
        $this->support->markRead($tenantId, $id, $messageId);
        $this->conversations->notify($tenantId, $establishmentId, $id, null);

        return self::presentMessage($this->conversations->message($tenantId, $establishmentId, $id, $messageId));
    }

    public function setStatus(string $id, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw HttpException::validation(['status' => ['Valeurs possibles : ' . implode(', ', self::STATUSES) . '.']]);
        }
        $row = $this->find($id);
        $this->support->update((string) $row['tenant_id'], $id, ['support_status' => $status, 'updated_at' => $this->clock->iso()]);

        return $this->conversation($id);
    }

    public function assign(string $id, ?string $platformUserId): array
    {
        $row = $this->find($id);
        $this->support->update((string) $row['tenant_id'], $id, ['support_assignee' => $platformUserId, 'updated_at' => $this->clock->iso()]);

        return $this->conversation($id);
    }

    /**
     * @param list<string> $participants personnes du client
     * @param callable(ChatService, string, string): string $firstMessage message initial (service, date, conversation)
     */
    private function create(string $tenantId, string $establishmentId, array $participants, string $createdBy, string $status, string $subject, callable $firstMessage, bool $readBySupport = false): string
    {
        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $messageId = $this->chat->transactional(function () use ($tenantId, $establishmentId, $participants, $createdBy, $status, $subject, $id, $now, $firstMessage): string {
            $this->store->insert('conversations', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'establishment_id' => $establishmentId,
                'subject' => $subject,
                'created_by' => $createdBy,
                'status' => ChatService::OPEN,
                'kind' => ChatService::SUPPORT,
                'learner_id' => null,
                'support_status' => $status,
                'last_message_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($participants as $personId) {
                $this->store->insert('conversation_participants', [
                    'id' => (string) new Ulid(),
                    'tenant_id' => $tenantId,
                    'establishment_id' => $establishmentId,
                    'conversation_id' => $id,
                    'person_id' => $personId,
                    'chat_role' => ChatService::CLIENT_ADMIN,
                    'created_at' => $now,
                ]);
            }

            return $firstMessage($this->conversations, $now, $id);
        });
        if ($readBySupport) {
            $this->support->markRead($tenantId, $id, $messageId);
        } else {
            $this->chat->markRead($tenantId, $id, $createdBy, $messageId);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return $this->support->find($id) ?? throw HttpException::notFound('Conversation de support introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $out = [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'establishment_id' => (string) $row['establishment_id'],
            'subject' => (string) $row['subject'],
            'status' => (string) $row['status'],
            'support_status' => $row['support_status'] ?? self::OPEN,
            'assignee' => $row['support_assignee'] ?? null,
            'opened_by' => $row['created_by'] === self::CREATED_BY_SUPPORT ? 'platform' : 'client',
            'last_message_at' => $row['last_message_at'] ?? null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
        if (array_key_exists('unread_count', $row)) {
            $out['unread_count'] = (int) $row['unread_count'];
        }

        return $out;
    }

    /**
     * Message vu par le support : le texte d'un message masqué par la modération de l'établissement n'est pas servi.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentMessage(array $row): array
    {
        $hidden = ($row['moderation_status'] ?? ChatService::VISIBLE) === ChatService::HIDDEN;

        return [
            'id' => (string) $row['id'],
            'conversation_id' => (string) $row['conversation_id'],
            'sender_id' => (string) $row['sender_id'],
            'sender_side' => ($row['sender_side'] ?? null) === SupportRepository::PLATFORM_SIDE ? 'platform' : 'client',
            'body' => $hidden ? null : (string) $row['body'],
            'moderation_status' => (string) ($row['moderation_status'] ?? ChatService::VISIBLE),
            'created_at' => (string) $row['created_at'],
        ];
    }
}
