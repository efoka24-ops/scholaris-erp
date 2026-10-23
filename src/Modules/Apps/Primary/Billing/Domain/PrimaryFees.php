<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Billing\Domain;

/**
 * Natures de frais propres au primaire (Q17, défaut à confirmer), ajoutées à celles du socle (registration, tuition, other) par la
 * clé de conteneur `billing.fee_kinds.primary`. Les montants et les tranches restent dans les grilles de frais de l'école.
 */
final class PrimaryFees
{
    public const KINDS = ['pta_fee', 'uniform', 'supplies', 'exam_fee', 'canteen', 'daycare', 'transport'];

    private function __construct()
    {
    }
}
