<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Councils\Infrastructure\PrimaryAdmissions;

/**
 * Conseil des maîtres : les décisions « admis » du dernier trimestre alimentent People\YearPromotion par la source d'admissions
 * du socle (`assessment.admission_sources.*`, contrat Assessment\Contracts\AdmissionDirectory).
 */
return [
    'assessment.admission_sources.primary' => [PrimaryAdmissions::class],
];
