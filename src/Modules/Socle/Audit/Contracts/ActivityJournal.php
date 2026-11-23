<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/**
 * Lecture TRANSVERSALE du journal d'audit, réservée au back-office de la plateforme (C-112) : journal d'activité de tous les
 * clients et comptes agrégés pour les indicateurs. Comme AuditTrail : jamais les empreintes, l'état « avant » ni l'appareil.
 */
interface ActivityJournal
{
    /**
     * Entrées les plus récentes d'abord (instant, client, rang décroissants), strictement après le curseur opaque `$before`.
     *
     * @param array{tenant_id?: string, actor_id?: string, action?: string, object_id?: string, from?: string, to?: string} $filters
     *        `action` : préfixe ; from / to : AAAA-MM-JJ inclus
     * @return list<array{tenant_id: string, seq: int, occurred_at: string, actor_id: ?string, action: string, object_type: string, object_id: string, details: array<string, mixed>, ip: ?string, cursor: string}>
     */
    public function search(array $filters, ?string $before, int $limit): array;

    /**
     * Nombre d'entrées de l'action exacte par jour (AAAA-MM-JJ), depuis le jour `$fromDate` inclus, tous clients.
     *
     * @return array<string, int>
     */
    public function dailyCounts(string $action, string $fromDate): array;

    /**
     * Nombre d'entrées par client depuis l'instant `$since` (ISO 8601) ; `$action` : préfixe facultatif.
     *
     * @return array<string, int>
     */
    public function countsByTenant(string $since, ?string $action = null): array;

    /** Nombre d'entrées de l'action exacte depuis l'instant `$since`, tous clients ou un compte d'un client. */
    public function count(string $action, string $since, ?string $tenantId = null, ?string $actorId = null): int;
}
