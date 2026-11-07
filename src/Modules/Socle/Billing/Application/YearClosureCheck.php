<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureCheck;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;

/**
 * Contrôle « Impayés » de la clôture d'une année. Bloquent la clôture : les opérations financières non terminées de
 * l'établissement (paiements en attente de confirmation, caisses ouvertes, annulations et remises en attente d'approbation),
 * qui rendraient le report des soldes inexact. Les soldes impayés de l'année, eux, sont reportés sur l'année suivante par
 * YearBalanceCarryOver ; ils sont décomptés dans le détail, et ne bloquent que si le paramètre
 * `billing.closure.block_on_unpaid` est activé.
 */
final class YearClosureCheck implements ClosureCheck
{
    public const KEY = 'billing.unpaid';
    public const LABEL = 'Impayés';

    public function __construct(private readonly Db $db, private readonly BillingSettings $settings)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(string $tenantId, string $establishmentId, string $academicYearId): ClosureFinding
    {
        $scope = [$tenantId, $establishmentId];
        $pendingPayments = $this->db->int("SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND establishment_id = ? AND status = 'pending'", $scope);
        $openCash = $this->db->int("SELECT COUNT(*) FROM cash_sessions WHERE tenant_id = ? AND establishment_id = ? AND status = 'open'", $scope);
        $reversals = $this->db->int("SELECT COUNT(*) FROM reversals WHERE tenant_id = ? AND establishment_id = ? AND status = 'pending'", $scope);
        $discounts = $this->db->int("SELECT COUNT(*) FROM discounts WHERE tenant_id = ? AND establishment_id = ? AND status = 'pending'", $scope);
        $unpaid = $this->db->one(
            "SELECT COUNT(*) AS n, COALESCE(SUM(balance_xaf), 0) AS total FROM invoices
             WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? AND status = 'open' AND balance_xaf > 0",
            [$tenantId, $establishmentId, $academicYearId],
        ) ?? ['n' => 0, 'total' => 0];
        $unpaidCount = (int) $unpaid['n'];
        $blocksOnUnpaid = $this->settings->closureBlocksOnUnpaid($tenantId, $establishmentId);

        $parts = [];
        foreach ([
            [$pendingPayments, 'paiement(s) en attente de confirmation'],
            [$openCash, 'caisse(s) ouverte(s)'],
            [$reversals, 'annulation(s) en attente d\'approbation'],
            [$discounts, 'remise(s) en attente d\'approbation'],
        ] as [$count, $label]) {
            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, $label);
            }
        }
        $blocking = $pendingPayments + $openCash + $reversals + $discounts + ($blocksOnUnpaid ? $unpaidCount : 0);
        if ($unpaidCount > 0) {
            $parts[] = sprintf('%d facture(s) impayée(s) pour %d XAF%s', $unpaidCount, (int) $unpaid['total'], $blocksOnUnpaid ? '' : ' (reportées sur l\'année suivante)');
        }

        return ClosureFinding::of(self::KEY, self::LABEL, $blocking, $parts === [] ? null : implode(' ; ', $parts));
    }
}
