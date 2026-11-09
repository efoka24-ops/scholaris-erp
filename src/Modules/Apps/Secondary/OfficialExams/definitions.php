<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Secondary\OfficialExams\Application\OfficialExamDocumentTypes;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Application\OfficialExamService;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamCandidateDirectory;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Infrastructure\PdoExamCandidateDirectory;

/** Services et types DOC-19/20/31 fournis par OfficialExams ; sessions et candidats en lecture (relance NOT-10 de Fees). */
return [
    'secondary.document_types.official_exams' => [OfficialExamDocumentTypes::class],
    OfficialExamService::class => autowire(),
    ExamCandidateDirectory::class => get(PdoExamCandidateDirectory::class),
];
