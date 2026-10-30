<?php

declare(strict_types=1);

namespace Tests\Support\Primary;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\EstablishmentBalances;

/** Doublure de la situation financière : solde restant dû en XAF par élève ; solde > 0 = en retard (données fictives). */
final class FakeBalances implements DebtorDirectory, EstablishmentBalances
{
    /** @var array<string, array<string, int>> */
    private array $balances = [];

    public function set(string $tenantId, string $establishmentId, string $learnerId, int $xaf): void
    {
        $this->balances[$tenantId . '|' . $establishmentId][$learnerId] = $xaf;
    }

    public function learnerIdsInArrears(string $tenantId, string $establishmentId): array
    {
        return array_keys(array_filter($this->balances[$tenantId . '|' . $establishmentId] ?? [], static fn (int $xaf): bool => $xaf > 0));
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int
    {
        return $this->balances[$tenantId . '|' . $establishmentId][$learnerId] ?? 0;
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool
    {
        return false;
    }

    public function balancesXaf(string $tenantId, string $establishmentId): array
    {
        return $this->balances[$tenantId . '|' . $establishmentId] ?? [];
    }
}
