<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Application;

use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;

/**
 * Blocages paramétrables liés aux impayés (RG-FIN-5) : notes, documents, réinscription, selon `billing.blocks` du socle.
 * Les cours et la composition ne sont JAMAIS bloqués, quel que soit le paramétrage ; toute autre portée non plus.
 */
final class FinancialHoldPolicy implements FinancialHolds
{
    public const BLOCKABLE = [self::RESULTS, self::DOCUMENTS, self::REINSCRIPTION];
    public const NEVER = [self::COURSES, self::EXAMS];

    public function __construct(private readonly DebtorDirectory $debtors)
    {
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $personId, string $scope): bool
    {
        if (!in_array($scope, self::BLOCKABLE, true)) {
            return false;
        }

        return $this->debtors->isBlocked($tenantId, $establishmentId, $personId, $scope);
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $personId): int
    {
        return $this->debtors->balanceXaf($tenantId, $establishmentId, $personId);
    }

    /** @return array<string, bool> état de chaque portée pour une personne */
    public function summary(string $tenantId, string $establishmentId, string $personId): array
    {
        $out = [];
        foreach ([...self::BLOCKABLE, ...self::NEVER] as $scope) {
            $out[$scope] = $this->isBlocked($tenantId, $establishmentId, $personId, $scope);
        }

        return $out;
    }
}
