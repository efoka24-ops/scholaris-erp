<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Types de document : propres à l'établissement, ou catalogue de la plateforme (client « * », lecture seule). */
final class TypeRepository
{
    private const VISIBLE = "((tenant_id = ? AND establishment_id = ?) OR tenant_id = '*')";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $code): ?array
    {
        return $this->decode($this->db->row(
            'SELECT * FROM document_types WHERE code = ? AND ' . self::VISIBLE . " ORDER BY CASE WHEN tenant_id = '*' THEN 1 ELSE 0 END",
            [$code, $tenantId, $establishmentId],
        ));
    }

    /** @return array<string, mixed>|null */
    public function findById(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->decode($this->db->row('SELECT * FROM document_types WHERE id = ? AND ' . self::VISIBLE, [$id, $tenantId, $establishmentId]));
    }

    /** @return list<array<string, mixed>> */
    public function all(string $tenantId, string $establishmentId): array
    {
        $rows = $this->db->rows('SELECT * FROM document_types WHERE ' . self::VISIBLE . ' ORDER BY code, tenant_id', [$tenantId, $establishmentId]);

        return array_values(array_filter(array_map($this->decode(...), $rows)));
    }

    public function existsOwn(string $tenantId, string $establishmentId, string $code): bool
    {
        return $this->db->row(
            'SELECT id FROM document_types WHERE tenant_id = ? AND establishment_id = ? AND code = ?',
            [$tenantId, $establishmentId, $code],
        ) !== null;
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $this->db->insert('document_types', $values);
    }

    /** @param array<string, scalar|null> $values colonnes modifiables, jamais le code ni la lettre */
    public function update(string $tenantId, string $establishmentId, string $id, array $values): void
    {
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $this->db->exec(
            'UPDATE document_types SET ' . $sets . ' WHERE id = ? AND tenant_id = ? AND establishment_id = ?',
            [...array_values($values), $id, $tenantId, $establishmentId],
        );
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['variables'] = (array) json_decode((string) $row['variables'], true);
        $row['qr_enabled'] = (int) $row['qr_enabled'] === 1;
        $row['blockable'] = (int) $row['blockable'] === 1;
        $row['system'] = $row['tenant_id'] === '*';

        return $row;
    }
}
