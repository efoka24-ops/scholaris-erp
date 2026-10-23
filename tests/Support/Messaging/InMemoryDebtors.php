<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;

/** Doublure des impayés (Billing) et des absents du jour (Attendance). */
final class InMemoryDebtors implements DebtorDirectory, AbsenteeDirectory
{
    /** @var array<string, int> "tenant|établissement|apprenant" => solde */
    private array $balances = [];
    /** @var array<string, list<string>> */
    private array $absent = [];

    public function owes(string $tenant, string $establishment, string $learner, int $amount): void
    {
        $this->balances[$tenant . '|' . $establishment . '|' . $learner] = $amount;
    }

    public function absent(string $tenant, string $establishment, string $learner): void
    {
        $this->absent[$tenant . '|' . $establishment][] = $learner;
    }

    public function learnerIdsInArrears(string $tenantId, string $establishmentId): array
    {
        $ids = [];
        $prefix = $tenantId . '|' . $establishmentId . '|';
        foreach ($this->balances as $key => $amount) {
            if (str_starts_with($key, $prefix) && $amount > 0) {
                $ids[] = substr($key, strlen($prefix));
            }
        }

        return $ids;
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int
    {
        return $this->balances[$tenantId . '|' . $establishmentId . '|' . $learnerId] ?? 0;
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool
    {
        return false;
    }

    public function absentLearnerIds(string $tenantId, string $establishmentId, string $date): array
    {
        return $this->absent[$tenantId . '|' . $establishmentId] ?? [];
    }
}
