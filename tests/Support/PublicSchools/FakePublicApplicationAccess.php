<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** Doublure du droit d'usage (Platform) par client et par application ; jamais ouverte par défaut. Mêmes règles que PdoApplicationAccess. */
final class FakePublicApplicationAccess implements ApplicationAccess
{
    /** @var array<string, string> « client:application » => état */
    private array $states = [];

    public function set(string $tenantId, string $appKey, ?string $state): void
    {
        if ($state === null) {
            unset($this->states[$tenantId . ':' . $appKey]);

            return;
        }
        $this->states[$tenantId . ':' . $appKey] = $state;
    }

    public function state(string $tenantId, string $appKey): ?string
    {
        return $this->states[$tenantId . ':' . $appKey] ?? null;
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
