<?php

declare(strict_types=1);

namespace Tests\Support\Platform;

use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;

/** Établissements accessibles déclarés par utilisateur. */
final class FixedAccessibleEstablishments implements AccessibleEstablishments
{
    /** @var array<string, list<string>> */
    private array $ids = [];

    public function set(string $tenantId, string $userId, string ...$establishments): void
    {
        $this->ids[$tenantId . '|' . $userId] = array_values($establishments);
    }

    public function idsFor(string $tenantId, string $userId): array
    {
        return $this->ids[$tenantId . '|' . $userId] ?? [];
    }
}
