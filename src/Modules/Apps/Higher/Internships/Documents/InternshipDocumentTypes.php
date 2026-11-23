<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Documents des stages (tableau DOC du CDC), modèles de travail sans texte légal inventé (decisions R35 : en-tête et mentions
 * officielles venant du profil documentaire de l'école) :
 *  DOC-20 convention de stage (CST ; étudiant, entreprise, directeur d'école ; QR) ;
 *  DOC-21 convocation à la soutenance (CSS ; directeur d'école ; sans QR) ;
 *  DOC-22 PV de soutenance (PVS ; membres du jury ; QR) ;
 *  DOC-23 fiche d'évaluation du stagiaire (FES ; tuteur ; sans QR).
 * Aucun n'est bloquable pour impayé (RG-DOC-7) : le quitus est déjà une condition de la soutenance (RG-STG-1).
 */
final class InternshipDocumentTypes implements DocumentTypeProvider
{
    public const CONVENTION = 'DOC-20';
    public const CONVOCATION = 'DOC-21';
    public const MINUTES = 'DOC-22';
    public const EVALUATION = 'DOC-23';

    /** `holder_name` est fourni par le moteur du socle (variable système) : jamais déclaré ici. */
    private const STUDENT = ['internship_ref'];
    private const INTERNSHIP = ['company_name', 'company_address', 'internship_subject', 'starts_on', 'ends_on', 'company_tutor', 'supervisor_name'];
    private const DEFENSE = ['thesis_title', 'defense_date', 'defense_time', 'defense_room', 'jury_line', 'president_name'];

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::CONVENTION,
                'CST',
                'Convention de stage',
                'Internship agreement',
                'internships',
                'directeur_ecole',
                true,
                false,
                [...self::STUDENT, ...self::INTERNSHIP],
                ['fr' => self::conventionBody('fr'), 'en' => self::conventionBody('en')],
                'other',
                typeLetter: 'C',
            ),
            new HigherDocumentType(
                self::CONVOCATION,
                'CSS',
                'Convocation à la soutenance',
                'Notice of defence',
                'internships',
                'directeur_ecole',
                false,
                false,
                [...self::STUDENT, ...self::DEFENSE],
                ['fr' => self::convocationBody('fr'), 'en' => self::convocationBody('en')],
                'other',
                typeLetter: 'V',
            ),
            new HigherDocumentType(
                self::MINUTES,
                'PVS',
                'Procès-verbal de soutenance',
                'Defence minutes',
                'internships',
                'president_jury',
                true,
                false,
                [...self::STUDENT, ...self::DEFENSE, 'grade_label', 'result_label', 'remarks'],
                ['fr' => self::minutesBody('fr'), 'en' => self::minutesBody('en')],
                'other',
                typeLetter: 'P',
            ),
            new HigherDocumentType(
                self::EVALUATION,
                'FES',
                'Fiche d\'évaluation du stagiaire',
                'Intern evaluation form',
                'internships',
                'tuteur_entreprise',
                false,
                false,
                [...self::STUDENT, ...self::INTERNSHIP, 'evaluation_date', 'evaluation_score', 'evaluation_summary'],
                ['fr' => self::evaluationBody('fr'), 'en' => self::evaluationBody('en')],
                'other',
                typeLetter: 'F',
            ),
        ];
    }

    private static function internshipBlock(string $locale): string
    {
        $l = $locale === 'en'
            ? ['Student', 'Host company', 'Subject', 'Period', 'to', 'Company tutor', 'Academic supervisor', 'Reference']
            : ['Étudiant(e)', 'Entreprise d\'accueil', 'Sujet', 'Période', 'au', 'Tuteur en entreprise', 'Encadreur académique', 'Référence'];

        return '<table class="identity"><tr><td>' . $l[0] . ' : <strong>{{holder_name}}</strong> ({{holder_matricule}})</td><td>{{program_name}} · {{level_name}}</td></tr>'
            . '<tr><td>' . $l[1] . ' : <strong>{{company_name}}</strong></td><td>{{company_address}}</td></tr>'
            . '<tr><td colspan="2">' . $l[2] . ' : {{internship_subject}}</td></tr>'
            . '<tr><td>' . $l[3] . ' : {{starts_on}} ' . $l[4] . ' {{ends_on}}</td><td>' . $l[7] . ' : {{internship_ref}}</td></tr>'
            . '<tr><td>' . $l[5] . ' : {{company_tutor}}</td><td>' . $l[6] . ' : {{supervisor_name}}</td></tr></table>';
    }

    private static function conventionBody(string $locale): string
    {
        return self::internshipBlock($locale) . ($locale === 'en'
            ? '<p>This agreement binds the student, the host company and the school for the internship described above.</p>'
                . '<table class="signatures"><tr><td>The student</td><td>The host company</td><td>The school director</td></tr></table>'
            : '<p>La présente convention lie l\'étudiant(e), l\'entreprise d\'accueil et l\'école pour le stage décrit ci-dessus.</p>'
                . '<table class="signatures"><tr><td>L\'étudiant(e)</td><td>L\'entreprise d\'accueil</td><td>Le directeur de l\'école</td></tr></table>');
    }

    private static function defenseBlock(string $locale): string
    {
        return $locale === 'en'
            ? '<p>Thesis: <strong>{{thesis_title}}</strong></p><p>Date: <strong>{{defense_date}}</strong> at {{defense_time}} · Room: {{defense_room}}</p>'
                . '<p>Chair: {{president_name}} · Board: {{jury_line}}</p>'
            : '<p>Mémoire : <strong>{{thesis_title}}</strong></p><p>Date : <strong>{{defense_date}}</strong> à {{defense_time}} · Salle : {{defense_room}}</p>'
                . '<p>Président : {{president_name}} · Jury : {{jury_line}}</p>';
    }

    private static function convocationBody(string $locale): string
    {
        return ($locale === 'en'
            ? '<p><strong>{{holder_name}}</strong> ({{holder_matricule}}, {{program_name}}) is invited to defend the thesis below.</p>'
            : '<p><strong>{{holder_name}}</strong> ({{holder_matricule}}, {{program_name}}) est convoqué(e) à la soutenance du mémoire ci-dessous.</p>')
            . self::defenseBlock($locale) . '<p><small>{{internship_ref}}</small></p>';
    }

    private static function minutesBody(string $locale): string
    {
        return ($locale === 'en'
            ? '<p>Defence of <strong>{{holder_name}}</strong> ({{holder_matricule}}, {{program_name}}, {{level_name}}).</p>'
            : '<p>Soutenance de <strong>{{holder_name}}</strong> ({{holder_matricule}}, {{program_name}}, {{level_name}}).</p>')
            . self::defenseBlock($locale)
            . ($locale === 'en'
                ? '<p>Mark: <strong>{{grade_label}}</strong> · Result: <strong>{{result_label}}</strong></p><p>Remarks: {{remarks}}</p><p>Signatures of the board members.</p>'
                : '<p>Note : <strong>{{grade_label}}</strong> · Résultat : <strong>{{result_label}}</strong></p><p>Observations : {{remarks}}</p><p>Signatures des membres du jury.</p>')
            . '<p><small>{{internship_ref}}</small></p>';
    }

    private static function evaluationBody(string $locale): string
    {
        return self::internshipBlock($locale) . ($locale === 'en'
            ? '<p>Evaluation of {{evaluation_date}}: <strong>{{evaluation_score}}</strong></p><p>{{evaluation_summary}}</p><p>The company tutor</p>'
            : '<p>Évaluation du {{evaluation_date}} : <strong>{{evaluation_score}}</strong></p><p>{{evaluation_summary}}</p><p>Le tuteur en entreprise</p>');
    }
}
