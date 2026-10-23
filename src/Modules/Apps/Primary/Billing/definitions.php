<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Billing\Domain\PrimaryFees;

/** Natures de frais du primaire, ajoutées à celles du socle (S10). */
return [
    'billing.fee_kinds.primary' => PrimaryFees::KINDS,
];
