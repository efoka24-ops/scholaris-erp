<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Documents transmis aux autorités, modèles de travail (decisions R35), signés par la direction générale, sans QR, jamais bloqués :
 *  DOC-18 liste des inscrits pour la tutelle (LIT ; le fichier de l'université est l'export `tutelle.enrolled`, ce document en est la
 *         page de garde) (DOC-32 est déclaré par Dashboards).
 */
final class TutelageDocumentTypes implements DocumentTypeProvider
{
    public const ENROLLED_LIST = 'DOC-18';

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::ENROLLED_LIST,
                'LIT',
                'Liste des inscrits pour la tutelle',
                'List of enrolled students for the supervising university',
                'tutelage',
                'direction_groupe',
                false,
                false,
                ['university_name', 'enrolled_count', 'transmitted_on', 'export_reference'],
                [
                    'fr' => '<p>Liste des étudiants inscrits transmise à {{university_name}} pour l\'année académique {{academic_year}} : {{enrolled_count}} étudiants, transmise le {{transmitted_on}}, fichier {{export_reference}}.</p>',
                    'en' => '<p>List of enrolled students sent to {{university_name}} for the academic year {{academic_year}}: {{enrolled_count}} students, sent on {{transmitted_on}}, file {{export_reference}}.</p>',
                ],
                'other',
            ),
        ];
    }
}
