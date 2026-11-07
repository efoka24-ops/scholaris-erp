<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/**
 * Lecture du journal d'audit d'UN client (le plus récent d'abord), pour un écran de consultation. Les empreintes,
 * l'état « avant » et l'appareil ne sortent jamais ; seuls les détails « après » (motifs, identifiants) sont rendus.
 */
interface AuditTrail
{
    /**
     * @param array{action?: string, actor_id?: string, object_type?: string, object_id?: string, from?: string, to?: string} $filters
     *        `action` : préfixe (« platform.client » trouve platform.client_suspended…) ; from / to : AAAA-MM-JJ inclus
     * @return list<array{seq: int, occurred_at: string, actor_id: ?string, action: string, object_type: string, object_id: string, details: array<string, mixed>, ip: ?string}>
     *         entrées de rang strictement inférieur à $beforeSeq (null : depuis la plus récente), par rang décroissant
     */
    public function recent(string $tenantId, array $filters, ?int $beforeSeq, int $limit): array;
}
