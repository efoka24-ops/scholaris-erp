<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/** Choix de la règle d'affectation à partir de son nom paramétré ; règle par défaut si le nom est inconnu. */
final class AllocationRules
{
    /**
     * @param list<string> $kindOrder ordre des natures de la règle « kind_priority » (`billing.allocation_kind_order`) ; vide = défaut
     */
    public static function byName(string $name, array $kindOrder = []): AllocationRule
    {
        return match ($name) {
            OldestFirstRule::NAME => new OldestFirstRule(),
            KindPriorityRule::NAME => new KindPriorityRule($kindOrder),
            default => new RegistrationOverdueUpcomingRule(),
        };
    }
}
