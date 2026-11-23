<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;

/** Doublure de DocumentCatalog. */
final class Documents implements DocumentCatalog
{
    /** @var array<string, list<IssuedDocument>> */
    private array $items = [];

    public function add(string $tenantId, IssuedDocument $document): void
    {
        $this->items[$tenantId . '|' . $document->subjectPersonId][] = $document;
    }

    public function validFor(string $tenantId, string $personId, int $limit = 50): array
    {
        return array_slice(array_values(array_filter(
            $this->items[$tenantId . '|' . $personId] ?? [],
            static fn (IssuedDocument $d): bool => $d->status === IssuedDocument::VALID,
        )), 0, $limit);
    }
}
