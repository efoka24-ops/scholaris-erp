<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Application\NationalIdRegistry;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\ExamFeeStatus;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;

/** Contrats de la carte scolaire offerts aux autres sous-modules Secondary (Enrolment, OfficialExams, Documents). */
return [
    NationalIds::class => get(NationalIdRegistry::class),
    ExamFeeStatus::class => get(NationalIdRegistry::class),
];
