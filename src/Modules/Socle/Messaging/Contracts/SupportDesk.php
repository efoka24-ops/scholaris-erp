<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Boîte du support SCHOLARIS, vue de la plateforme. Cloisonnement : ce contrat ne lit et n'écrit QUE les conversations
 * de nature « support » ; une conversation interne d'un établissement (parent, enseignant, direction) y est introuvable (404).
 * Fourni par Messaging ; utilisé par le back-office (module Platform), qui vérifie les permissions platform.support.*.
 */
interface SupportDesk
{
    public const OPEN = 'open';
    public const PENDING = 'pending';
    public const RESOLVED = 'resolved';
    public const STATUSES = [self::OPEN, self::PENDING, self::RESOLVED];

    /**
     * Conversations de support, de la dernière activité à la plus ancienne.
     *
     * @param array{status?: string, tenant_id?: string, assignee?: string} $filters assignee « none » = non assignées
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function inbox(array $filters, ?string $cursor, int $perPage): array;

    /**
     * @return array<string, mixed> conversation de support (404 si absente ou d'une autre nature), avec ses participants
     */
    public function conversation(string $id): array;

    /**
     * Messages, du plus récent au plus ancien ; marque la conversation comme lue par le support.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function messages(string $id, ?string $cursor, int $perPage): array;

    /** @return array<string, mixed> message posté par le support (statut passé à « en attente » du client) */
    public function reply(string $id, string $platformUserId, string $body): array;

    /** @return array<string, mixed> conversation mise à jour */
    public function setStatus(string $id, string $status): array;

    /** @return array<string, mixed> conversation mise à jour ; $platformUserId null = désassigner */
    public function assign(string $id, ?string $platformUserId): array;

    /**
     * Ouvre une conversation du support vers des personnes (administrateurs) d'un client, dans un de ses établissements.
     *
     * @param list<string> $recipientPersonIds
     * @return array<string, mixed> la conversation créée
     */
    public function openToClient(string $tenantId, string $establishmentId, array $recipientPersonIds, string $platformUserId, string $subject, string $body): array;
}
