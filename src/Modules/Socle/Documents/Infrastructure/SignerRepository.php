<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use Scholaris\Core\Support\Ulid;

/** Signataires par rôle (S17) : pour tout l'établissement (org_unit_id = '') ou pour une école interne. */
final class SignerRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $role, string $orgUnitId = ''): ?array
    {
        return $this->db->row(
            'SELECT * FROM document_signers WHERE tenant_id = ? AND establishment_id = ? AND signer_role = ? AND org_unit_id = ?',
            [$tenantId, $establishmentId, $role, $orgUnitId],
        );
    }

    /** @return array<string, mixed>|null signataire de l'école, sinon celui de l'établissement */
    public function resolve(string $tenantId, string $establishmentId, string $role, ?string $orgUnitId): ?array
    {
        return ($orgUnitId !== null && $orgUnitId !== '' ? $this->find($tenantId, $establishmentId, $role, $orgUnitId) : null)
            ?? $this->find($tenantId, $establishmentId, $role);
    }

    /** @param array<string, scalar|null> $values */
    public function save(string $tenantId, string $establishmentId, string $role, string $orgUnitId, array $values): void
    {
        $this->db->exec(
            'DELETE FROM document_signers WHERE tenant_id = ? AND establishment_id = ? AND signer_role = ? AND org_unit_id = ?',
            [$tenantId, $establishmentId, $role, $orgUnitId],
        );
        $this->db->insert('document_signers', [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId,
            'signer_role' => $role, 'org_unit_id' => $orgUnitId,
        ] + $values);
    }
}
