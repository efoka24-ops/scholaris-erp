<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/** Choix de la règle d'affectation à partir de son nom paramétré ; règle par défaut si le nom est inconnu. */
final class AllocationRules
{
    public static function byName(string $name): AllocationRule
    {
        return match ($name) {
            OldestFirstRule::NAME => new OldestFirstRule(),
            default => new RegistrationOverdueUpcomingRule(),
        };
    }
}
