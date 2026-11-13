<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Modèles HTML versionnés. Une version déjà utilisée par un document n'est jamais modifiée ni supprimée. */
final class TemplateRepository
{
    private const VISIBLE = "((tenant_id = ? AND establishment_id = ?) OR tenant_id = '*')";

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null dernière version validée (celle de l'établissement d'abord, sinon celle de la plateforme) */
    public function latestValidated(string $tenantId, string $establishmentId, string $typeCode, string $locale): ?array
    {
        return $this->db->row(
            'SELECT * FROM document_templates WHERE type_code = ? AND locale = ? AND validated_at IS NOT NULL AND ' . self::VISIBLE
            . " ORDER BY CASE WHEN tenant_id = '*' THEN 1 ELSE 0 END, version DESC",
            [$typeCode, $locale, $tenantId, $establishmentId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->row('SELECT * FROM document_templates WHERE id = ? AND ' . self::VISIBLE, [$id, $tenantId, $establishmentId]);
    }

    /** @return list<array<string, mixed>> */
    public function all(string $tenantId, string $establishmentId, ?string $typeCode = null): array
    {
        $sql = 'SELECT * FROM document_templates WHERE ' . self::VISIBLE;
        $params = [$tenantId, $establishmentId];
        if ($typeCode !== null) {
            $sql .= ' AND type_code = ?';
            $params[] = $typeCode;
        }

        return $this->db->rows($sql . ' ORDER BY type_code, locale, version DESC', $params);
    }

    public function nextVersion(string $tenantId, string $establishmentId, string $typeCode, string $locale): int
    {
        $row = $this->db->row(
            'SELECT MAX(version) AS v FROM document_templates WHERE tenant_id = ? AND establishment_id = ? AND type_code = ? AND locale = ?',
            [$tenantId, $establishmentId, $typeCode, $locale],
        );

        return (int) ($row['v'] ?? 0) + 1;
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $this->db->insert('document_templates', $values);
    }

    public function updateHtml(string $tenantId, string $establishmentId, string $id, string $html): void
    {
        $this->db->exec(
            'UPDATE document_templates SET html = ? WHERE id = ? AND tenant_id = ? AND establishment_id = ? AND validated_at IS NULL',
            [$html, $id, $tenantId, $establishmentId],
        );
    }

    public function validate(string $tenantId, string $establishmentId, string $id, string $at): void
    {
        $this->db->exec(
            'UPDATE document_templates SET validated_at = ? WHERE id = ? AND tenant_id = ? AND establishment_id = ? AND validated_at IS NULL',
            [$at, $id, $tenantId, $establishmentId],
        );
    }

    public function delete(string $tenantId, string $establishmentId, string $id): void
    {
        $this->db->exec(
            'DELETE FROM document_templates WHERE id = ? AND tenant_id = ? AND establishment_id = ? AND validated_at IS NULL',
            [$id, $tenantId, $establishmentId],
        );
    }

    public function isUsed(string $tenantId, string $id): bool
    {
        return $this->db->row('SELECT id FROM documents WHERE tenant_id = ? AND template_id = ?', [$tenantId, $id]) !== null;
    }
}
