<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Contrat SORTANT local : report des soldes (ex. créances de l'année close vers la suivante). Fournisseur attendu : Billing, enregistré
 * sous une entrée de liste `organisation.closure.carry_over.<module>`. DOIT être idempotent pour le couple (année close, année suivante) :
 * le rejouer ne reporte rien deux fois. Sans fournisseur, l'étape est indiquée « indisponible » et la clôture continue.
 */
interface BalanceCarryOver
{
    public function carryOver(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult;
}
