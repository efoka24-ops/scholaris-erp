<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;

/** Doublure de ResultsDirectory. */
final class Results implements ResultsDirectory
{
    /** @var array<string, list<PublishedResult>> */
    private array $items = [];

    public function add(string $tenantId, PublishedResult $result): void
    {
        $this->items[$tenantId . '|' . $result->personId][] = $result;
    }

    public function publishedFor(string $tenantId, string $personId): array
    {
        return $this->items[$tenantId . '|' . $personId] ?? [];
    }
}
