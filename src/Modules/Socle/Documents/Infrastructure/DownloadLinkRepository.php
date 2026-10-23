<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Liens de téléchargement à durée limitée (SS14) et journal de leurs consultations publiques (IP hachée, jamais le jeton). */
final class DownloadLinkRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $values */
    public function insert(array $values): void
    {
        $this->db->insert('document_download_links', $values);
    }

    /** @return array<string, mixed>|null */
    public function findByHash(string $tokenHash): ?array
    {
        return $this->db->row('SELECT * FROM document_download_links WHERE token_hash = ?', [$tokenHash]);
    }

    /** Consomme une utilisation si le plafond n'est pas atteint ; 0 si le lien est épuisé (concurrence comprise). */
    public function consume(string $id, string $at): int
    {
        return $this->db->exec(
            'UPDATE document_download_links SET use_count = use_count + 1, last_used_at = ? WHERE id = ? AND use_count < max_uses',
            [$at, $id],
        );
    }

    public function log(string $id, string $tenantId, ?string $linkId, string $outcome, ?string $ip, string $at): void
    {
        $this->db->insert('document_download_log', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'link_id' => $linkId,
            'outcome' => $outcome,
            'ip_hash' => hash('sha256', 'scholaris-download|' . ($ip ?? '')),
            'accessed_at' => $at,
        ]);
    }
}
