<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Contrat SORTANT local : un contrôle préalable à la clôture d'une année (impayés, notes non verrouillées, délibérations ouvertes).
 * Les modules propriétaires des données l'implémentent et s'enregistrent dans leur definitions.php sous une entrée de liste
 * `organisation.closure.checks.<module>` (tableau de noms de classes). Les clés attendues sont celles de YearClosureService::EXPECTED :
 * sans fournisseur pour une clé, le contrôle est « non évaluable » et listé.
 */
interface ClosureCheck
{
    /** Clé du contrôle, ex. « billing.unpaid ». */
    public function key(): string;

    public function evaluate(string $tenantId, string $establishmentId, string $academicYearId): ClosureFinding;
}
