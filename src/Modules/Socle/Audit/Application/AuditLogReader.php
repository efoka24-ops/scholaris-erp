<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use Scholaris\Modules\Socle\Audit\Infrastructure\PrivacyDb;

/**
 * Lecture du journal d'audit d'un client pour export : colonnes de la liste blanche uniquement (rang, date, auteur, action,
 * objet). Les états avant / après, l'adresse IP, l'appareil et les empreintes ne sortent jamais.
 */
final class AuditLogReader
{
    public function __construct(private readonly PrivacyDb $db)
    {
    }

    /**
     * @param array<string, string> $filters actor_id, action, object_type, from (AAAA-MM-JJ), to (AAAA-MM-JJ)
     * @return list<array<string, mixed>> entrées de rang supérieur à $afterSeq, par rang croissant
     */
    public function page(string $tenantId, array $filters, int $afterSeq, int $limit): array
    {
        $sql = 'SELECT seq, occurred_at, actor_id, action, object_type, object_id FROM audit_log WHERE tenant_id = ? AND seq > ?';
        $params = [$tenantId, $afterSeq];
        foreach (['actor_id', 'action', 'object_type'] as $column) {
            if (isset($filters[$column])) {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$column];
            }
        }
        if (isset($filters['from'])) {
            $sql .= ' AND occurred_at >= ?';
            $params[] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $sql .= ' AND occurred_at <= ?';
            $params[] = $filters['to'] . '~';
        }

        return $this->db->rows($sql . sprintf(' ORDER BY seq ASC LIMIT %d', max(1, $limit)), $params);
    }
}
