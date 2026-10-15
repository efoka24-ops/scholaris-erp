<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Documents émis. Aucune suppression : seul le statut évolue (valid vers revoked). */
final class DocumentRepository
{
    /** Colonnes du registre : le contenu rendu n'en fait pas partie. */
    private const LIST_COLUMNS = 'id, tenant_id, establishment_id, type_code, category, number, verification_token, fingerprint, status, template_id,'
        . ' template_version, locale, subject_person_id, holder_name, revoke_reason, revoked_at, revoked_by, replaced_by, issued_by, issued_via,'
        . ' issued_at, signed_at';

    /** Filtres du registre : colonne SQL de chaque filtre de la liste blanche. */
    private const FILTER_COLUMNS = [
        'status' => 'status',
        'type' => 'type_code',
        'subject_person_id' => 'subject_person_id',
        'issued_via' => 'issued_via',
        'number' => 'number',
    ];

    private const SORT_COLUMNS = ['issued_at' => 'issued_at', 'number' => 'number', 'type' => 'type_code'];

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $this->db->insert('documents', $values);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $id, ?string $establishmentId = null): ?array
    {
        $sql = 'SELECT * FROM documents WHERE id = ? AND tenant_id = ?';
        $params = [$id, $tenantId];
        if ($establishmentId !== null) {
            $sql .= ' AND establishment_id = ?';
            $params[] = $establishmentId;
        }

        return $this->db->row($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        return $this->db->row('SELECT * FROM documents WHERE verification_token = ?', [$token]);
    }

    /**
     * @param array<string, string> $filters filtres de la liste blanche (view, status, type, ...)
     * @param list<array{0: string, 1: 'asc'|'desc'}> $sorts
     * @return list<array<string, mixed>>
     */
    public function search(string $tenantId, string $establishmentId, array $filters, array $sorts, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($tenantId, $establishmentId, $filters);
        $order = [];
        foreach ($sorts as [$field, $direction]) {
            $order[] = self::SORT_COLUMNS[$field] . ' ' . ($direction === 'desc' ? 'DESC' : 'ASC');
        }
        $order[] = 'id DESC';

        return $this->db->rows(
            sprintf('SELECT %s FROM documents WHERE %s ORDER BY %s LIMIT %d OFFSET %d', self::LIST_COLUMNS, $where, implode(', ', $order), $limit, $offset),
            $params,
        );
    }

    /** @param array<string, string> $filters */
    public function count(string $tenantId, string $establishmentId, array $filters): int
    {
        [$where, $params] = $this->conditions($tenantId, $establishmentId, $filters);

        return (int) ($this->db->row('SELECT COUNT(*) AS n FROM documents WHERE ' . $where, $params)['n'] ?? 0);
    }

    public function markRevoked(string $tenantId, string $id, string $reason, string $by, string $at, ?string $replacedBy): int
    {
        return $this->db->exec(
            "UPDATE documents SET status = 'revoked', revoke_reason = ?, revoked_by = ?, revoked_at = ?, replaced_by = ? WHERE id = ? AND tenant_id = ? AND status = 'valid'",
            [$reason, $by, $at, $replacedBy, $id, $tenantId],
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function conditions(string $tenantId, string $establishmentId, array $filters): array
    {
        $where = ['tenant_id = ?', 'establishment_id = ?'];
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $name => $value) {
            if ($name === 'view') {
                match ($value) {
                    'bulletins' => $where[] = "category = 'bulletin'",
                    'certificates' => $where[] = "category = 'certificate'",
                    'revoked' => $where[] = "status = 'revoked'",
                    default => null,
                };
                continue;
            }
            if (isset(self::FILTER_COLUMNS[$name])) {
                $where[] = self::FILTER_COLUMNS[$name] . ' = ?';
                $params[] = $value;
            }
        }

        return [implode(' AND ', $where), $params];
    }
}
