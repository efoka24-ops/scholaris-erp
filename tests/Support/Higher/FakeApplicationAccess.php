<?php

declare(strict_types=1);

namespace Tests\Support\Higher;

use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** Doublure du droit d'usage (Platform) : état fixé par client ; jamais ouverte par défaut. Mêmes règles que PdoApplicationAccess. */
final class FakeApplicationAccess implements ApplicationAccess
{
    /** @var array<string, string> client => état */
    private array $states = [];

    public function set(string $tenantId, ?string $state): void
    {
        if ($state === null) {
            unset($this->states[$tenantId]);

            return;
        }
        $this->states[$tenantId] = $state;
    }

    public function state(string $tenantId, string $appKey): ?string
    {
        return $appKey === 'higher' ? ($this->states[$tenantId] ?? null) : null;
    }

    public function allowsWrites(string $tenantId, string $appKey): bool
    {
        return in_array($this->state($tenantId, $appKey), [self::TRIAL, self::ACTIVE, self::PAST_DUE], true);
    }

    public function allowsReads(string $tenantId, string $appKey): bool
    {
        $state = $this->state($tenantId, $appKey);

        return $state !== null && $state !== self::CLOSED;
    }
}
