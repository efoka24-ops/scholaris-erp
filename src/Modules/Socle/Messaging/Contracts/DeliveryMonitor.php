<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Suivi des envois de TOUS les clients pour le back-office de la plateforme (C-112), en lecture : file d'envoi, comptes par
 * canal et statut, journal des SMS et e-mails (destinataire MASQUÉ, jamais le texte du message), comptes des messages
 * internes par client (jamais leur contenu) ; seule écriture : relancer une livraison en échec.
 */
interface DeliveryMonitor
{
    /** @return array{pending: int, failed: int, oldest_pending_at: ?string} file d'envoi (en attente = pending ou queued) */
    public function queue(): array;

    /**
     * Livraisons créées depuis `$since` (ISO 8601), par canal puis statut.
     *
     * @return array<string, array<string, int>> ex. ['sms' => ['sent' => 3, 'delivered' => 2, 'failed' => 1]]
     */
    public function countsSince(string $since): array;

    /**
     * Journal des livraisons, la plus récente d'abord (identifiant décroissant, strictement avant `$beforeId`).
     *
     * @param array{channel?: string, tenant_id?: string, status?: string, recipient_id?: string, from?: string, to?: string} $filters dates AAAA-MM-JJ incluses ; recipient_id : personne destinataire (avec tenant_id)
     * @return list<array{id: string, tenant_id: string, channel: string, recipient: string, template_key: ?string, status: string, reason: ?string, provider_reference: ?string, attempts: int, created_at: string, sent_at: ?string, delivered_at: ?string}>
     */
    public function journal(array $filters, ?string $beforeId, int $limit): array;

    /** Remet en file une livraison en échec (une fois) : faux si elle n'existe pas ou n'est pas en échec. */
    public function retry(string $tenantId, string $deliveryId): bool;

    /**
     * Messagerie interne : nombre de conversations et de messages par client (aucun contenu lu).
     *
     * @return list<array{tenant_id: string, conversations: int, messages: int, hidden: int}>
     */
    public function internalCounts(): array;
}
