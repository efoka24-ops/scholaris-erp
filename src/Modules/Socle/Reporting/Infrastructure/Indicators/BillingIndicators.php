<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\EstablishmentBalances;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;

/**
 * Retards et soldes, d'après DebtorDirectory. Le taux de recouvrement exige le total facturé, que le contrat
 * n'expose pas : il reste indisponible tant qu'un fournisseur d'application ne le produit pas.
 *
 * Quand le fournisseur expose aussi EstablishmentBalances (cas de Billing), les soldes sont lus en une seule requête
 * groupée au lieu d'une requête par apprenant (P1 : lanceur et tableau de bord d'un établissement de 5 000 élèves).
 */
final class BillingIndicators implements IndicatorProvider
{
    public function __construct(private readonly DebtorDirectory $debtors, private readonly EnrollmentDirectory $enrollments)
    {
    }

    public function module(): string
    {
        return 'billing';
    }

    public function indicators(IndicatorScope $scope): array
    {
        $learners = array_unique($this->enrollments->personIdsInEstablishment($scope->tenantId, $scope->establishmentId));
        $outstanding = 0;
        if ($this->debtors instanceof EstablishmentBalances) {
            $balances = $this->debtors->balancesXaf($scope->tenantId, $scope->establishmentId);
            foreach ($learners as $learnerId) {
                $outstanding += max(0, $balances[$learnerId] ?? 0);
            }
        } else {
            foreach ($learners as $learnerId) {
                $outstanding += max(0, $this->debtors->balanceXaf($scope->tenantId, $scope->establishmentId, $learnerId));
            }
        }

        return [
            Indicator::of('billing.learners_in_arrears', 'Apprenants en retard de paiement', count(array_unique($this->debtors->learnerIdsInArrears($scope->tenantId, $scope->establishmentId)))),
            Indicator::of('billing.outstanding_xaf', 'Solde restant dû (FCFA)', $outstanding, 'xaf'),
            Indicator::unavailable('billing.recovery_rate', 'Taux de recouvrement', 'Le total facturé n\'est exposé par aucun contrat.', 'percent'),
        ];
    }
}
