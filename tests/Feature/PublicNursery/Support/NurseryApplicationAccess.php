<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery\Support;

use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** Droit d'usage de `public_nursery` piloté par le test : actif par défaut, suspendu ou fermé à la demande. */
final class NurseryApplicationAccess implements ApplicationAccess
{
    public function __construct(private string $state = self::ACTIVE)
    {
    }

    public function become(string $state): void
    {
        $this->state = $state;
    }

    public function state(string $tenantId, string $appKey): ?string
    {
        return $this->state;
    }

    public function allowsWrites(string $tenantId, string $appKey): bool
    {
        return in_array($this->state, [self::TRIAL, self::ACTIVE, self::PAST_DUE], true);
    }

    public function allowsReads(string $tenantId, string $appKey): bool
    {
        return $this->state !== self::CLOSED;
    }
}
