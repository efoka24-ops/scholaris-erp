<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;

/** Doublure de DocumentIssuer : types connus configurables, émissions enregistrées. */
final class RecordingIssuer implements DocumentIssuer
{
    /** @var list<string> */
    public array $knownTypes = ['report_card', 'class_council_minutes', 'deliberation_minutes'];

    /** @var list<array{type: string, person: string, data: array<string, scalar|null>}> */
    public array $issued = [];

    public function issue(string $tenantId, string $establishmentId, string $type, string $subjectPersonId, array $data, string $issuedBy, string $locale = 'fr'): IssuedDocument
    {
        if (!in_array($type, $this->knownTypes, true)) {
            throw HttpException::validation(['type' => ['Type de document inconnu : ' . $type . '.']]);
        }
        $this->issued[] = ['type' => $type, 'person' => $subjectPersonId, 'data' => $data];

        return new IssuedDocument('doc-' . count($this->issued), 'N-' . count($this->issued), 'tok', IssuedDocument::VALID, $type, $subjectPersonId);
    }

    public function revoke(string $tenantId, string $documentId, string $reason, string $revokedBy): void
    {
    }
}
