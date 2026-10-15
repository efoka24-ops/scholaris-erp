<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;

/** Doublure de DebtorDirectory : soldes et blocages explicites. */
final class Debtors implements DebtorDirectory
{
    /** @var array<string, int> */
    private array $balances = [];

    /** @var array<string, true> */
    private array $blocked = [];

    public function setBalance(string $tenantId, string $establishmentId, string $learnerId, int $xaf): void
    {
        $this->balances[$tenantId . '|' . $establishmentId . '|' . $learnerId] = $xaf;
    }

    public function block(string $tenantId, string $establishmentId, string $learnerId, string $scope): void
    {
        $this->blocked[$tenantId . '|' . $establishmentId . '|' . $learnerId . '|' . $scope] = true;
    }

    public function learnerIdsInArrears(string $tenantId, string $establishmentId): array
    {
        $out = [];
        foreach ($this->balances as $key => $amount) {
            [$t, $e, $l] = explode('|', $key);
            if ($t === $tenantId && $e === $establishmentId && $amount > 0) {
                $out[] = $l;
            }
        }

        return $out;
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int
    {
        return $this->balances[$tenantId . '|' . $establishmentId . '|' . $learnerId] ?? 0;
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool
    {
        return isset($this->blocked[$tenantId . '|' . $establishmentId . '|' . $learnerId . '|' . $scope]);
    }
}
