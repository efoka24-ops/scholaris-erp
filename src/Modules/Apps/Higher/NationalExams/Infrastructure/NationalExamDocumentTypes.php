<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Documents des examens nationaux, modèles de travail (aucun texte légal inventé ; intitulés sans « BTS » ni « HND », RG-EXN-3,
 * decisions C10 : la spécialité est une variable) :
 *  DOC-12 attestation de fin de formation (AFF), distincte du diplôme national, émise après la délibération annuelle signée
 *         (garde de Deliberation) ; DOC-17 fiche de candidature à l'examen national (FCE), dossier joint à la liste MINESUP.
 */
final class NationalExamDocumentTypes implements DocumentTypeProvider
{
    public const COMPLETION = 'DOC-12';
    public const CANDIDATE_RECORD = 'DOC-17';
    public const CODES = [self::COMPLETION, self::CANDIDATE_RECORD];

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::COMPLETION,
                'AFF',
                'Attestation de fin de formation',
                'Certificate of completion of training',
                'national_exams',
                'directeur_ecole',
                true,
                true,
                ['specialty', 'training_period'],
                [
                    'fr' => '<p>Le directeur atteste que <strong>{{holder_name}}</strong>, matricule {{holder_matricule}}, a suivi la formation de la spécialité {{specialty}} ({{program_name}}, {{level_name}}) au titre de l\'année académique {{academic_year}}, période {{training_period}}.</p><p>La présente attestation ne tient pas lieu de diplôme national, délivré par l\'autorité compétente.</p>',
                    'en' => '<p>The director certifies that <strong>{{holder_name}}</strong>, registration no. {{holder_matricule}}, attended the training in {{specialty}} ({{program_name}}, {{level_name}}) for the academic year {{academic_year}}, period {{training_period}}.</p><p>This certificate does not replace the national diploma, awarded by the competent authority.</p>',
                ],
            ),
            new HigherDocumentType(
                self::CANDIDATE_RECORD,
                'FCE',
                'Fiche de candidature à l\'examen national',
                'National examination candidate record',
                'national_exams',
                'directeur_ecole',
                false,
                false,
                ['specialty', 'candidate_number', 'exam_centre', 'presence_percent', 'fee_receipt'],
                [
                    'fr' => '<p>Candidat : <strong>{{holder_name}}</strong>, matricule {{holder_matricule}}.</p><p>Spécialité : {{specialty}} ({{program_name}}, {{level_name}}), année académique {{academic_year}}.</p><p>N° de candidat : {{candidate_number}} ; centre : {{exam_centre}} ; assiduité : {{presence_percent}} % ; reçu des frais : {{fee_receipt}}.</p>',
                    'en' => '<p>Candidate: <strong>{{holder_name}}</strong>, registration no. {{holder_matricule}}.</p><p>Specialty: {{specialty}} ({{program_name}}, {{level_name}}), academic year {{academic_year}}.</p><p>Candidate no.: {{candidate_number}}; centre: {{exam_centre}}; attendance: {{presence_percent}}%; fee receipt: {{fee_receipt}}.</p>',
                ],
                'other',
            ),
        ];
    }
}
