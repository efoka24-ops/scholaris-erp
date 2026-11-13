<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/**
 * File de pré-rendu des PDF de bulletins (C-118). Comme la file des lots, elle est transversale aux clients : `candidates()`
 * est la seule lecture sans filtre de client ; la prise (`claim`) et la fin (`rendered`) sont bornées par le document et son
 * client. La prise est un UPDATE conditionnel : deux processus ne rendent jamais le même document en même temps.
 */
final class PdfRenderQueue
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id: string, tenant_id: string, establishment_id: string}> */
    public function candidates(string $staleBefore, int $limit): array
    {
        $rows = $this->db->rows(
            sprintf(
                "SELECT id, tenant_id, establishment_id FROM documents WHERE category = 'bulletin' AND status = 'valid' AND pdf_rendered_at IS NULL "
                . 'AND (pdf_claimed_at IS NULL OR pdf_claimed_at < ?) ORDER BY issued_at, id LIMIT %d',
                max(1, $limit),
            ),
            [$staleBefore],
        );

        return array_map(
            static fn (array $r): array => ['id' => (string) $r['id'], 'tenant_id' => (string) $r['tenant_id'], 'establishment_id' => (string) $r['establishment_id']],
            $rows,
        );
    }

    public function claim(string $tenantId, string $id, string $staleBefore, string $at): bool
    {
        return $this->db->exec(
            'UPDATE documents SET pdf_claimed_at = ? WHERE id = ? AND tenant_id = ? AND pdf_rendered_at IS NULL AND (pdf_claimed_at IS NULL OR pdf_claimed_at < ?)',
            [$at, $id, $tenantId, $staleBefore],
        ) === 1;
    }

    public function rendered(string $tenantId, string $id, string $profile, string $at): void
    {
        $this->db->exec('UPDATE documents SET pdf_rendered_at = ?, pdf_profile = ? WHERE id = ? AND tenant_id = ?', [$at, $profile, $id, $tenantId]);
    }
}
