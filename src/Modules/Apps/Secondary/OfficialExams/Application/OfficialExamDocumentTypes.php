<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\OfficialExams\Application;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocumentType as Type;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/** Déclarations DOC-19/20/31 pour les lots Documents ; les modèles doivent être déclarés au catalogue Socle Documents. */
final class OfficialExamDocumentTypes implements DocumentTypeProvider
{
    public function types(): array
    {
        return [
            new Type('DOC-19', 'official_exam_candidate', 'sec_doc19_official_exam_candidate', 'Fiche individuelle de candidat à un examen', 'Official exam candidate record', false, Type::SOURCE_CALLER, ['secondary.exams.lists'], Modules::OFFICIAL_EXAMS, false),
            new Type('DOC-20', 'official_exam_candidate_list', 'sec_doc20_official_exam_candidate_list', 'Liste des candidats à un examen', 'Official exam candidate list', false, Type::SOURCE_CALLER, ['secondary.exams.lists'], Modules::OFFICIAL_EXAMS, false),
            new Type('DOC-31', 'official_exam_attestation', 'sec_doc31_official_exam_attestation', 'Attestation interne de résultat à un examen', 'Internal examination result statement', true, Type::SOURCE_CALLER, ['secondary.exams.validate'], Modules::OFFICIAL_EXAMS, false),
        ];
    }
}
