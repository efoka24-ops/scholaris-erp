<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Assessment\Contracts\IssuedReportCards;

/** Contrat IssuedReportCards : conseil vérifié dans l'établissement, puis demandes de bulletin émises avec leur document. */
final class PdoIssuedReportCards implements IssuedReportCards
{
    public function __construct(private readonly ResultRepository $repo)
    {
    }

    public function issuedFor(string $tenantId, string $establishmentId, string $councilId): array
    {
        if ($this->repo->council($tenantId, $establishmentId, $councilId) === null) {
            return [];
        }
        $out = [];
        foreach ($this->repo->reportCards($tenantId, $councilId, 'issued') as $row) {
            if ($row['document_id'] !== null && $row['document_id'] !== '') {
                $out[] = ['person_id' => (string) $row['person_id'], 'document_id' => (string) $row['document_id']];
            }
        }

        return $out;
    }
}
