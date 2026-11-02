<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Documents de la délibération (tableau DOC du CDC), modèles de travail (decisions R35 : en-tête et mentions officielles venant du
 * profil documentaire de l'école ; aucun texte légal inventé) :
 *  DOC-09 relevé de notes semestriel (REL, après signature du PV, directeur d'école, QR) : en-tête (n° d'autorisation, tutelle LMD),
 *         identité, corps par UE et EC (note/20, moyenne d'UE, lettre, points, résultat, session), synthèse (moyenne, MGP, crédits
 *         acquis/possibles, décision, mention), pied commun (numéro REL-EC-AAAA-NNNNNN, QR, « Seul l'original signé ou vérifié en ligne fait foi ») ;
 *  DOC-10 relevé annuel et cumulatif (RAN) ; DOC-14 grille de délibération (GRD, document de travail, sans QR) ;
 *  DOC-15 PV de délibération (PVD, président et membres, QR, empreinte SHA-256, points de jury nominatifs) ;
 *  DOC-16 liste des résultats à afficher (LRA, après signature du PV, directeur d'école, QR).
 * Le corps HTML n'accepte que des valeurs échappées : les lignes variables sont des emplacements numérotés (u01_…, l01…) ; depuis DOC-BUL
 * les relevés DOC-09 et DOC-10 affichent un tableau produit par le moteur du socle ({{table:units_table}}, {{table:annual_table}}), les
 * emplacements restant déclarés pour les modèles déjà installés.
 */
final class DeliberationDocumentTypes implements DocumentTypeProvider
{
    public const TRANSCRIPT = 'DOC-09';
    public const ANNUAL_TRANSCRIPT = 'DOC-10';
    public const GRID = 'DOC-14';
    public const MINUTES = 'DOC-15';
    public const RESULTS_LIST = 'DOC-16';

    /** Codes dont l'émission exige un PV signé (RG-DOC-3) ; DOC-11 et DOC-12 (attestations) exigent une décision annuelle. */
    public const REQUIRE_SIGNED_MINUTES = ['hi_doc09', 'hi_doc10', 'hi_doc16'];
    public const REQUIRE_YEAR_DECISION = ['hi_doc11', 'hi_doc12'];

    public const UNIT_SLOTS = 10;
    public const ANNUAL_SLOTS = 20;
    public const LINE_SLOTS = 60;
    public const POINT_SLOTS = 20;
    public const UNIT_FIELDS = ['code', 'title', 'credits', 'elements', 'average', 'letter', 'points', 'result', 'session'];
    public const ANNUAL_FIELDS = ['code', 'title', 'credits', 'average', 'letter', 'result'];

    public static function slot(int $index): string
    {
        return sprintf('%02d', $index);
    }

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::TRANSCRIPT,
                'REL',
                'Relevé de notes semestriel',
                'Semester transcript',
                'deliberation',
                'directeur_ecole',
                true,
                true,
                [...self::identity(), 'semester_label', 'session_label', ...self::slots('u', self::UNIT_SLOTS, self::UNIT_FIELDS), ...self::synthesis(), 'jury_ref', 'units_table', 'rank_info'],
                ['fr' => self::transcriptBody('fr'), 'en' => self::transcriptBody('en')],
                'bulletin',
                typeLetter: 'R',
            ),
            new HigherDocumentType(
                self::ANNUAL_TRANSCRIPT,
                'RAN',
                'Relevé annuel et cumulatif',
                'Annual and cumulative transcript',
                'deliberation',
                'directeur_ecole',
                true,
                true,
                [...self::identity(), 's1_label', 's1_average', 's1_credits', 's2_label', 's2_average', 's2_credits', 'cumulative_credits',
                    ...self::slots('a', self::ANNUAL_SLOTS, self::ANNUAL_FIELDS), ...self::synthesis(), 'jury_ref', 'annual_table', 'rank_info'],
                ['fr' => self::annualBody('fr'), 'en' => self::annualBody('en')],
                'bulletin',
                typeLetter: 'R',
            ),
            new HigherDocumentType(
                self::GRID,
                'GRD',
                'Grille de délibération',
                'Deliberation grid',
                'deliberation',
                null,
                false,
                false,
                ['group_label', 'semester_label', 'session_label', 'students_count', 'summary_line', ...self::lines('l', self::LINE_SLOTS)],
                ['fr' => self::gridBody('fr'), 'en' => self::gridBody('en')],
                'other',
                typeLetter: 'G',
            ),
            new HigherDocumentType(
                self::MINUTES,
                'PVD',
                'Procès-verbal de délibération',
                'Deliberation minutes',
                'deliberation',
                'president_jury',
                true,
                false,
                ['group_label', 'semester_label', 'session_label', 'president_name', 'members_line', 'present_line', 'quorum_line', 'agenda',
                    'summary_line', 'minutes_version', 'fingerprint', 'signatures_line', ...self::lines('l', self::LINE_SLOTS), ...self::lines('p', self::POINT_SLOTS)],
                ['fr' => self::minutesBody('fr'), 'en' => self::minutesBody('en')],
                'other',
                typeLetter: 'P',
            ),
            new HigherDocumentType(
                self::RESULTS_LIST,
                'LRA',
                'Liste des résultats',
                'Results list',
                'deliberation',
                'directeur_ecole',
                true,
                false,
                ['group_label', 'semester_label', 'session_label', 'summary_line', 'jury_ref', ...self::lines('l', self::LINE_SLOTS)],
                ['fr' => self::listBody('fr'), 'en' => self::listBody('en')],
                'other',
                typeLetter: 'L',
            ),
        ];
    }

    /** @return list<string> */
    private static function identity(): array
    {
        return ['holder_last_name', 'holder_first_names', 'birth_info', 'authorization_ref', 'tutelage_name'];
    }

    /** @return list<string> */
    private static function synthesis(): array
    {
        return ['semester_average', 'gpa', 'credits_earned', 'credits_total', 'decision', 'mention'];
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    public static function slots(string $prefix, int $count, array $fields): array
    {
        $out = [];
        for ($i = 1; $i <= $count; ++$i) {
            foreach ($fields as $field) {
                $out[] = $prefix . self::slot($i) . '_' . $field;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function lines(string $prefix, int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; ++$i) {
            $out[] = $prefix . self::slot($i);
        }

        return $out;
    }

    /** Libellé bilingue, langue du document d'abord (relevés bilingues des modèles réels). */
    private static function bi(string $locale, string $fr, string $en): string
    {
        return $locale === 'en' ? $en . ' / <i>' . $fr . '</i>' : $fr . ' / <i>' . $en . '</i>';
    }

    /** Mise en forme des relevés (DOC-BUL) : une page A4 portrait, tableau produit par le moteur du socle ({{table:x}}). */
    private static function style(): string
    {
        return '<style>@page{margin:1cm 1.2cm}body{font-size:9.5px;line-height:1.3}header h1{font-size:15px;margin:2px 0}header h2{font-size:12px;margin:2px 0}'
            . 'header img{float:left}header p{margin:2px 0;font-size:8.5px}h2{font-size:13px;margin:6px 0 4px 0}'
            . '.authority{text-align:center;font-size:8.5px;margin:2px 0}.identity,.synthesis{width:100%;border-collapse:collapse;margin:4px 0}'
            . '.identity td{padding:1px 4px;border:1px solid #999}.synthesis td{padding:3px 4px;border:1px solid #555}'
            . '.doc-table{font-size:8.5px}.doc-table th{font-size:8px}.doc-row-group td{background:#eef2f7}.note{font-size:8px;font-style:italic}</style>';
    }

    private static function header(string $locale): string
    {
        return $locale === 'en'
            ? '<p class="authority">Authorisation no. {{authorization_ref}} · Supervising university: {{tutelage_name}}</p>'
            : '<p class="authority">Autorisation n° {{authorization_ref}} · Université de tutelle : {{tutelage_name}}</p>';
    }

    private static function identityBlock(string $locale, string $period): string
    {
        $l = static fn (string $fr, string $en): string => self::bi($locale, $fr, $en);

        return '<table class="identity"><tr><td>' . $l('Nom', 'Surname') . ' : <strong>{{holder_last_name}}</strong></td><td>' . $l('Prénoms', 'Given names') . ' : <strong>{{holder_first_names}}</strong></td></tr>'
            . '<tr><td>' . $l('Né(e) le', 'Date of birth') . ' : {{birth_info}}</td><td>' . $l('Matricule', 'Registration no.') . ' : <strong>{{holder_matricule}}</strong></td></tr>'
            . '<tr><td>' . $l('Filière', 'Programme') . ' : {{program_name}}</td><td>' . $l('Niveau', 'Level') . ' : {{level_name}}</td></tr>'
            . '<tr><td>' . $l('Période', 'Period') . ' : ' . $period . '</td><td>' . $l('Année académique', 'Academic year') . ' : {{academic_year}}</td></tr></table>';
    }

    private static function synthesisBlock(string $locale): string
    {
        $l = static fn (string $fr, string $en): string => self::bi($locale, $fr, $en);

        return '<table class="synthesis"><tr><td>' . $l('Moyenne', 'Average') . ' : <strong>{{semester_average}}</strong>/20</td>'
            . '<td>' . $l('MGP', 'GPA') . ' : <strong>{{gpa}}</strong>/4</td>'
            . '<td>' . $l('Crédits capitalisés', 'Credits earned') . ' : <strong>{{credits_earned}}</strong>/{{credits_total}}</td>'
            . '<td>' . $l('Rang', 'Rank') . ' : {{rank_info}}</td></tr>'
            . '<tr><td colspan="3">' . $l('Décision du jury', 'Board decision') . ' : <strong>{{decision}}</strong></td><td>' . $l('Mention', 'Honours') . ' : {{mention}}</td></tr></table>'
            . '<p class="note">' . ($locale === 'en' ? 'Deliberation ref.' : 'Délibération réf.') . ' {{jury_ref}}</p>';
    }

    private static function transcriptBody(string $locale): string
    {
        return self::style() . self::header($locale) . self::identityBlock($locale, '{{semester_label}} ({{session_label}})')
            . '<div class="units">{{table:units_table}}</div>' . self::synthesisBlock($locale);
    }

    private static function annualBody(string $locale): string
    {
        $semesters = $locale === 'en'
            ? '<p>{{s1_label}}: {{s1_average}}/20, {{s1_credits}} credits · {{s2_label}}: {{s2_average}}/20, {{s2_credits}} credits · Cumulative credits: <strong>{{cumulative_credits}}</strong></p>'
            : '<p>{{s1_label}} : {{s1_average}}/20, {{s1_credits}} crédits · {{s2_label}} : {{s2_average}}/20, {{s2_credits}} crédits · Crédits cumulés : <strong>{{cumulative_credits}}</strong></p>';

        return self::style() . self::header($locale) . self::identityBlock($locale, '{{academic_year}}')
            . '<div class="units">{{table:annual_table}}</div>' . $semesters . self::synthesisBlock($locale);
    }


    private static function linesBlock(string $prefix, int $count): string
    {
        $out = '<ol class="lines">';
        for ($i = 1; $i <= $count; ++$i) {
            $out .= '<li>{{' . $prefix . self::slot($i) . '}}</li>';
        }

        return $out . '</ol>';
    }

    private static function gridBody(string $locale): string
    {
        return ($locale === 'en'
            ? '<p>Working document, not for display. Group {{group_label}} · {{semester_label}} · {{session_label}} · {{students_count}} students.</p>'
            : '<p>Document de travail, non affichable. Groupe {{group_label}} · {{semester_label}} · {{session_label}} · {{students_count}} étudiants.</p>')
            . '<p>{{summary_line}}</p>' . self::linesBlock('l', self::LINE_SLOTS);
    }

    private static function minutesBody(string $locale): string
    {
        if ($locale === 'en') {
            return '<p>Group {{group_label}} · {{semester_label}} · {{session_label}} · minutes version {{minutes_version}}</p>'
                . '<p>Chair: <strong>{{president_name}}</strong> · Members: {{members_line}}</p><p>Present: {{present_line}} · {{quorum_line}}</p>'
                . '<p>Agenda: {{agenda}}</p><p>{{summary_line}}</p><h3>Decisions</h3>' . self::linesBlock('l', self::LINE_SLOTS)
                . '<h3>Board points (named)</h3>' . self::linesBlock('p', self::POINT_SLOTS)
                . '<p>Signatures: {{signatures_line}}</p><p><small>SHA-256 fingerprint of the decisions: {{fingerprint}}</small></p>';
        }

        return '<p>Groupe {{group_label}} · {{semester_label}} · {{session_label}} · procès-verbal version {{minutes_version}}</p>'
            . '<p>Président : <strong>{{president_name}}</strong> · Membres : {{members_line}}</p><p>Présents : {{present_line}} · {{quorum_line}}</p>'
            . '<p>Ordre du jour : {{agenda}}</p><p>{{summary_line}}</p><h3>Décisions</h3>' . self::linesBlock('l', self::LINE_SLOTS)
            . '<h3>Points de jury (liste nominative)</h3>' . self::linesBlock('p', self::POINT_SLOTS)
            . '<p>Signatures : {{signatures_line}}</p><p><small>Empreinte SHA-256 des décisions : {{fingerprint}}</small></p>';
    }

    private static function listBody(string $locale): string
    {
        return ($locale === 'en'
            ? '<p>Group {{group_label}} · {{semester_label}} · {{session_label}} · published after signature of the minutes ({{jury_ref}}).</p>'
            : '<p>Groupe {{group_label}} · {{semester_label}} · {{session_label}} · publiée après signature du procès-verbal ({{jury_ref}}).</p>')
            . '<p>{{summary_line}}</p>' . self::linesBlock('l', self::LINE_SLOTS);
    }
}
