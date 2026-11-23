<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Contrat SORTANT local : passage en classe supérieure des apprenants admis. Fournisseur attendu : People ou Assessment, enregistré
 * sous une entrée de liste `organisation.closure.promotion.<module>`. DOIT être idempotent pour le couple (année close, année suivante).
 * Sans fournisseur, l'étape est indiquée « indisponible » et la clôture continue.
 */
interface PromotionPlanner
{
    public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult;
}
