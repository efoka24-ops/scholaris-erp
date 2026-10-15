<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use DateTimeImmutable;
use Scholaris\Modules\Socle\Billing\Application\BillingClock;
use Scholaris\Modules\Socle\Billing\Application\BillingSettings;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\EstablishmentBalances;

/**
 * Situation financière des apprenants. Les blocages d'impayé sont paramétrés (billing.blocks) et désactivés par défaut ;
 * ils ne visent que les résultats, les documents et la réinscription : jamais les cours, la composition, le certificat
 * de scolarité, le reçu, le relevé de compte ni l'attestation de paiement.
 */
final class PdoDebtorDirectory implements DebtorDirectory, EstablishmentBalances
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly BillingSettings $settings,
        private readonly BillingClock $clock,
    ) {
    }

    public function learnerIdsInArrears(string $tenantId, string $establishmentId): array
    {
        return $this->invoices->learnersInArrears($tenantId, $establishmentId, $this->clock->today());
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int
    {
        return $this->invoices->balance($tenantId, $establishmentId, $learnerId);
    }

    public function balancesXaf(string $tenantId, string $establishmentId): array
    {
        return $this->invoices->balancesByLearner($tenantId, $establishmentId);
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool
    {
        $policy = $this->settings->blockPolicy($tenantId, $establishmentId, $scope);
        if (!$policy['enabled']) {
            return false;
        }

        $today = $this->clock->today();
        $overdue = $this->invoices->overdue($tenantId, $establishmentId, $learnerId, $today);
        if ($overdue['oldest'] === null || $overdue['amount'] < $policy['min_amount_xaf']) {
            return false;
        }
        $days = (int) (new DateTimeImmutable($overdue['oldest']))->diff(new DateTimeImmutable($today))->format('%a');

        return $days >= $policy['min_overdue_days'];
    }
}
