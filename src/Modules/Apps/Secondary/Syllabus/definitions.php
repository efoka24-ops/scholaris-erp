<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Syllabus\Application\CoverageService;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageReader;

/** Liaisons du cahier de textes : couverture des programmes offerte aux autres sous-modules (Councils, tableaux de bord). */
return [
    CoverageReader::class => get(CoverageService::class),
];
