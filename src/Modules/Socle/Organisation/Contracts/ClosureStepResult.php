<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Résultat d'une étape de clôture confiée à un autre module (report des soldes, passage en classe supérieure). */
final class ClosureStepResult
{
    public const DONE = 'done';
    public const UNAVAILABLE = 'unavailable';
    public const SKIPPED = 'skipped';

    /** @param array<string, int> $counts comptes utiles (ex. soldes reportés) */
    public function __construct(
        public readonly string $status,
        public readonly string $summary,
        public readonly array $counts = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status, 'summary' => $this->summary, 'counts' => $this->counts];
    }
}
