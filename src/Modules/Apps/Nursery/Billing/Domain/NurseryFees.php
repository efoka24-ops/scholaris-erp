<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Billing\Domain;

/**
 * Natures de frais de la Maternelle privée (MQ-10, défaut à confirmer), ajoutées à celles du socle par la clé `billing.fee_kinds.nursery`.
 * Les montants, tranches, échéances et la remise de fratrie restent dans les grilles de frais et les paramètres `billing.*` de l'école.
 */
final class NurseryFees
{
    public const KINDS = ['registration', 'tuition', 'daycare', 'canteen', 'transport', 'uniform', 'supplies', 'other'];

    private function __construct()
    {
    }
}
