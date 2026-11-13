<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Syllabus\Application\CoverageService;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageReader;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\HomeworkReader;
use Scholaris\Modules\Apps\Secondary\Syllabus\Infrastructure\PdoHomeworkReader;

/**
 * Liaisons du cahier de textes : couverture des programmes offerte aux autres sous-modules (Councils, tableaux de bord) et devoirs
 * donnés, en lecture, pour les portails.
 */
return [
    CoverageReader::class => get(CoverageService::class),
    HomeworkReader::class => get(PdoHomeworkReader::class),
];
