<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Signature électronique simple et cachet d'un établissement. */
final class SealRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId): ?array
    {
        return $this->db->row('SELECT * FROM document_seals WHERE tenant_id = ? AND establishment_id = ?', [$tenantId, $establishmentId]);
    }

    /** @param array<string, scalar|null> $values */
    public function save(string $tenantId, string $establishmentId, array $values): void
    {
        $this->db->exec('DELETE FROM document_seals WHERE tenant_id = ? AND establishment_id = ?', [$tenantId, $establishmentId]);
        $this->db->insert('document_seals', ['tenant_id' => $tenantId, 'establishment_id' => $establishmentId] + $values);
    }
}
