<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/** Règle d'affectation d'un paiement aux tranches : ordre dans lequel les sommes dues sont couvertes. */
interface AllocationRule
{
    /** Nom stable de la règle (valeur du paramètre billing.allocation_rule). */
    public function name(): string;

    /** Version enregistrée avec chaque affectation (ex. « registration_overdue_upcoming@1 »). */
    public function version(): string;

    /**
     * @param list<Due> $dues
     * @return list<Due>
     */
    public function order(array $dues, string $today): array;
}
