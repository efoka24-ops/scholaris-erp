<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;

final class InMemoryDebtors implements DebtorDirectory
{
    /** @var array<string, true> */
    private array $blocked = [];

    public function block(string $learnerId): void
    {
        $this->blocked[$learnerId] = true;
    }

    public function learnerIdsInArrears(string $tenantId, string $establishmentId): array
    {
        return array_keys($this->blocked);
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int
    {
        return isset($this->blocked[$learnerId]) ? 50000 : 0;
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool
    {
        return $scope === 'documents' && isset($this->blocked[$learnerId]);
    }
}
