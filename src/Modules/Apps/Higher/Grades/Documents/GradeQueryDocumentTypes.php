<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * DOC-19 requête de note et décision (RQN ; étudiant puis chef de département ; sans QR ; jamais bloquée pour impayé). Modèle de
 * travail (decisions R35). La note n'est jamais reprise ici : le document reproduit la requête et la décision motivée.
 */
final class GradeQueryDocumentTypes implements DocumentTypeProvider
{
    public const QUERY = 'DOC-19';

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::QUERY,
                'RQN',
                'Requête de note et décision',
                'Grade query and decision',
                'grades',
                'higher_chef_dept',
                false,
                false,
                ['query_ref', 'element_title', 'query_message', 'decision', 'decision_reason', 'decided_on'],
                [
                    'fr' => '<p>Requête {{query_ref}} de <strong>{{holder_name}}</strong>, matricule {{holder_matricule}}, sur l\'élément {{element_title}} ({{academic_year}}) : {{query_message}}</p><p>Décision du chef de département le {{decided_on}} : {{decision}}. Motif : {{decision_reason}}</p>',
                    'en' => '<p>Query {{query_ref}} by <strong>{{holder_name}}</strong>, registration no. {{holder_matricule}}, on the course element {{element_title}} ({{academic_year}}): {{query_message}}</p><p>Head of department decision on {{decided_on}}: {{decision}}. Reason: {{decision_reason}}</p>',
                ],
                'other',
                typeLetter: 'R',
            ),
        ];
    }
}
