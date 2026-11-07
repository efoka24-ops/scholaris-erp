<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/** Résultat du calcul d'affectation : montants par tranche et surplus conservé en crédit. */
final class AllocationPlan
{
    /** @param list<array{due: Due, amount: int}> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly int $surplus,
        public readonly string $ruleVersion,
    ) {
    }

    /** @return array<string, int> montant affecté par identifiant de tranche */
    public function byInstallment(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            $out[$line['due']->installmentId] = ($out[$line['due']->installmentId] ?? 0) + $line['amount'];
        }

        return $out;
    }
}
