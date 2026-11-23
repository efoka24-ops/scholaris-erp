<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

use InvalidArgumentException;

/** Calcul pur de l'affectation d'un montant entier (XAF) aux tranches selon une règle. */
final class AllocationPlanner
{
    /** @param list<Due> $dues */
    public function plan(int $amount, array $dues, AllocationRule $rule, string $today): AllocationPlan
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Le montant à affecter ne peut pas être négatif.');
        }

        $remaining = $amount;
        $lines = [];
        foreach ($rule->order(array_values(array_filter($dues, static fn (Due $d): bool => $d->outstanding > 0)), $today) as $due) {
            if ($remaining === 0) {
                break;
            }
            $part = min($remaining, $due->outstanding);
            $lines[] = ['due' => $due, 'amount' => $part];
            $remaining -= $part;
        }

        return new AllocationPlan($lines, $remaining, $rule->version());
    }
}
