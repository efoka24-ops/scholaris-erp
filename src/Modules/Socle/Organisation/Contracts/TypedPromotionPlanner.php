<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Planificateur de passage propre à un ou plusieurs types d'établissement (D8). Enregistré comme tout PromotionPlanner
 * (entrée de liste `organisation.closure.promotion.<module>`). À la clôture d'un établissement de ce type, les planificateurs typés
 * qui le visent REMPLACENT les planificateurs génériques (ex. Supérieur remplace People\YearPromotion pour `higher`) ; ils sont
 * ignorés pour les autres types.
 */
interface TypedPromotionPlanner extends PromotionPlanner
{
    /** @return list<string> types visés (nursery, primary, secondary, higher) */
    public function establishmentTypes(): array;
}
