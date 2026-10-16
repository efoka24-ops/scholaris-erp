<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Planificateur de passage conditionnel : même contrat qu'un TypedPromotionPlanner, mais il ne s'applique
 * à la clôture que si appliesTo() retourne true pour l'établissement visé.
 */
interface ConditionalPromotionPlanner extends TypedPromotionPlanner
{
    public function appliesTo(string $tenantId, string $establishmentId): bool;
}