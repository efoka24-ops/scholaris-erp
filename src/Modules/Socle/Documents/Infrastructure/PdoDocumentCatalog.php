<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;

/** Documents valides d'une personne pour les portails : jamais les révoqués, jamais le contenu rendu. */
final class PdoDocumentCatalog implements DocumentCatalog
{
    public function __construct(private readonly Db $db)
    {
    }

    public function validFor(string $tenantId, string $personId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->db->rows(
            sprintf(
                'SELECT id, number, verification_token, status, type_code, subject_person_id FROM documents WHERE tenant_id = ? AND subject_person_id = ? AND status = ? ORDER BY issued_at DESC, id DESC LIMIT %d',
                $limit,
            ),
            [$tenantId, $personId, IssuedDocument::VALID],
        );

        return array_map(static fn (array $r): IssuedDocument => new IssuedDocument(
            (string) $r['id'],
            (string) $r['number'],
            (string) $r['verification_token'],
            (string) $r['status'],
            (string) $r['type_code'],
            (string) $r['subject_person_id'],
        ), $rows);
    }
}
