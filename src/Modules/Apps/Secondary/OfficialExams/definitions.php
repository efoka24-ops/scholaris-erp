<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Secondary\OfficialExams\Application\OfficialExamDocumentTypes;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Application\OfficialExamService;

/** Services et types DOC-19/20/31 fournis par OfficialExams. */
return [
    'secondary.document_types.official_exams' => [OfficialExamDocumentTypes::class],
    OfficialExamService::class => autowire(),
];
