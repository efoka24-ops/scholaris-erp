<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Documents de l'enseignement (tableau des documents du CDC) : DOC-07 emploi du temps (à la demande, sans signataire), DOC-08 liste
 * d'émargement d'examen (surveillants), DOC-13 convocation aux examens ou au rattrapage (automatique). Aucun n'est bloqué pour impayé :
 * aucun blocage n'empêche cours ni composition (RG-FIN-5). Modèles de travail (decisions.md R35).
 */
final class TeachingDocumentTypes implements DocumentTypeProvider
{
    public const TIMETABLE = 'DOC-07';
    public const EXAM_SHEET = 'DOC-08';
    public const EXAM_NOTICE = 'DOC-13';

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::TIMETABLE,
                'EDT',
                'Emploi du temps',
                'Timetable',
                'teaching',
                null,
                false,
                false,
                ['group_name', 'week_of', 'timetable_summary'],
                [
                    'fr' => '<p>Emploi du temps de <strong>{{holder_name}}</strong>, groupe {{group_name}}, semaine du {{week_of}}.</p><p>{{timetable_summary}}</p><p>Document indicatif : les modifications sont notifiées.</p>',
                    'en' => '<p>Timetable of <strong>{{holder_name}}</strong>, group {{group_name}}, week of {{week_of}}.</p><p>{{timetable_summary}}</p><p>For information: changes are notified.</p>',
                ],
                'other',
                typeLetter: 'E',
            ),
            new HigherDocumentType(
                self::EXAM_SHEET,
                'EME',
                'Liste d\'émargement d\'examen',
                'Examination attendance sheet',
                'teaching',
                'surveillant',
                false,
                false,
                ['exam_subject', 'exam_date', 'exam_time', 'exam_room', 'candidates_count', 'barred_count'],
                [
                    'fr' => '<p>Épreuve : {{exam_subject}}, le {{exam_date}} ({{exam_time}}), salle {{exam_room}}.</p><p>Candidats autorisés : {{candidates_count}} ; non autorisés à composer : {{barred_count}}.</p><p>Surveillant : {{holder_name}}.</p>',
                    'en' => '<p>Paper: {{exam_subject}}, on {{exam_date}} ({{exam_time}}), room {{exam_room}}.</p><p>Authorised candidates: {{candidates_count}}; not allowed to sit: {{barred_count}}.</p><p>Invigilator: {{holder_name}}.</p>',
                ],
                'other',
                typeLetter: 'E',
            ),
            new HigherDocumentType(
                self::EXAM_NOTICE,
                'CVE',
                'Convocation aux examens',
                'Examination notice',
                'teaching',
                null,
                false,
                false,
                ['exam_subject', 'exam_date', 'exam_time', 'exam_room', 'exam_session'],
                [
                    'fr' => '<p><strong>{{holder_name}}</strong> (matricule {{holder_matricule}}) est convoqué(e) à l\'épreuve {{exam_subject}} ({{exam_session}}) le {{exam_date}}, {{exam_time}}, salle {{exam_room}}.</p><p>Se présenter muni(e) de sa carte d\'étudiant.</p>',
                    'en' => '<p><strong>{{holder_name}}</strong> (registration number {{holder_matricule}}) is invited to sit {{exam_subject}} ({{exam_session}}) on {{exam_date}}, {{exam_time}}, room {{exam_room}}.</p><p>Please bring your student card.</p>',
                ],
                'other',
                typeLetter: 'C',
            ),
        ];
    }
}
