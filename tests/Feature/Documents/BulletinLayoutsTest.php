<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\TranscriptService;
use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes;
use Scholaris\Modules\Apps\Higher\Documents\Application\HigherDocumentCatalog;
use Scholaris\Modules\Apps\Primary\ReportCards\Application\ReportCardService as PrimaryReportCards;
use Scholaris\Modules\Apps\Primary\ReportCards\Application\SnapshotBuilder;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\ReportCardService as PublicReportCards;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\LibraryQrCodeGenerator;

/**
 * DOC-BUL : chaque modèle de bulletin ou de relevé (migrations 2026103110xx, relevés de l'IPES) est rendu avec des données FICTIVES
 * passées par le vrai constructeur de variables de l'application, contrôlé par la garde, puis converti en PDF (Dompdf) : une seule
 * page A4 portrait. Avec DOC_BUL_EXAMPLES=1, les PDF d'exemple sont déposés dans specs/006-audit-primaire-maternelle/exemples-documents.
 */
final class BulletinLayoutsTest extends TestCase
{
    private const MIGRATIONS = [
        'database/migrations/202610311001_doc_bul_primary_report_cards.sql',
        'database/migrations/202610311002_doc_bul_public_primary_report_cards.sql',
        'database/migrations/202610311003_doc_bul_secondary_report_cards.sql',
    ];

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function layouts(): iterable
    {
        foreach (['pri_report_card', 'pri_annual_report', 'pp_doc08_unit_report', 'pp_doc09_trimester_report', 'pp_doc10_annual_booklet', 'secondary_report_trimester', 'secondary_report_annual', 'hi_doc09'] as $type) {
            foreach (['fr', 'en'] as $locale) {
                yield $type . ' ' . $locale => [$type, $locale];
            }
        }
    }

    /** @dataProvider layouts */
    public function testLeModeleTientSurUnePageA4EtEchappeLesDonnees(string $type, string $locale): void
    {
        [$html, $declared] = $type === 'hi_doc09' ? self::higherTemplate($locale) : self::migrationTemplate($type, $locale);
        TemplateGuard::assertValid($html, $declared);
        self::assertStringNotContainsString(';', $type === 'hi_doc09' ? '' : $html, 'Le Migrator découpe sur « ; ».');

        $values = self::values($type, $locale);
        $unknown = array_diff(array_keys($values), $declared);
        self::assertSame([], array_values($unknown), 'Variables non déclarées par le type.');
        $render = static fn (array $v): string => TemplateRenderer::render($html, $v + [
            'number' => 'ECF-26-B-000042', 'verification_url' => 'https://verif.scholaris.example/v/EXEMPLEFICTIF', 'holder_name' => self::HOLDER,
            'establishment_name' => self::school($type), 'issued_on' => $locale === 'fr' ? '31/10/2026' : '2026-10-31', 'year' => '2026',
        ], ['qr' => (new LibraryQrCodeGenerator())->markup('https://verif.scholaris.example/v/EXEMPLEFICTIF'), 'signature' => '<div class="signature"><div>Signataire fictif</div></div>', 'stamp' => '']);
        $content = $render($values);
        $probe = $type === 'hi_doc09' ? 'decision' : 'appraisal';
        self::assertStringNotContainsString('<b>x</b>', $render([$probe => $values[$probe] . '<b>x</b>'] + $values), 'Valeurs échappées.');
        self::assertStringContainsString('<table class="doc-table', $content);
        if (str_starts_with($type, 'pp_')) {
            self::assertStringNotContainsStringIgnoringCase('rang', $content, 'RG-EVA-1 : aucun rang.');
            self::assertStringNotContainsStringIgnoringCase('apee', $content, 'RG-DOC-3.');
        }

        $pdf = (new DompdfRenderer(sys_get_temp_dir() . '/scholaris-dompdf-test'))->render(DocumentService::withPrintStyle($content));
        self::assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'Une seule page A4.');
        if (getenv('DOC_BUL_EXAMPLES') === '1') {
            $dir = dirname(__DIR__, 3) . '/specs/006-audit-primaire-maternelle/exemples-documents';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir . '/' . $type . '-' . $locale . '.pdf', $pdf);
            file_put_contents(sys_get_temp_dir() . '/doc-bul-' . $type . '-' . $locale . '.html', DocumentService::withPrintStyle($content));
        }
    }

    public function testLeTableauDuSecondaireSuitLeModeleAnnuel(): void
    {
        $values = self::values('secondary_report_annual', 'fr');
        $table = json_decode((string) $values['secondary_subjects_table'], true);

        self::assertSame(['TRIMESTRE 1', 'SEQ1'], [$table['head'][0][2]['text'], $table['head'][1][0]]);
        self::assertSame('total', $table['rows'][6]['kind']);
    }

    private const HOLDER = 'MBARGA ESSOMBA Nadège (fictif)';

    private static function school(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'pp_') => 'École publique d\'exemple de Ndogpassi (fictive)',
            str_starts_with($type, 'pri_') => 'École privée Les Flamboyants (fictive)',
            str_starts_with($type, 'secondary_') => 'Lycée d\'exemple de Bonamoussadi (fictif)',
            default => 'Institut supérieur d\'exemple (fictif)',
        };
    }

    /** @return array{0: string, 1: list<string>} */
    private static function migrationTemplate(string $type, string $locale): array
    {
        $html = null;
        $declared = self::updatedVariables($type);
        foreach (self::MIGRATIONS as $file) {
            $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            if (preg_match("#INSERT INTO document_templates [^\n]*VALUES \('[^']*', '\*', '', '" . $type . "', '" . $locale . "', '((?:[^']|'')*)', \d+,#", $sql, $m) === 1) {
                $html = str_replace("''", "'", $m[1]);
            }
            if (preg_match("#INSERT INTO document_types [^\n]*'" . $type . "', '[^']*', 'bulletin', '[A-Z]', '[^']*', \d, \d, '([^']*)'#", $sql, $t) === 1) {
                $declared = json_decode($t[1], true);
            }
        }
        self::assertIsString($html, 'Modèle introuvable : ' . $type . ' ' . $locale);
        self::assertIsArray($declared);
        self::assertNotSame([], $declared);

        return [$html, $declared];
    }

    /** @return list<string> variables du type après la mise à jour de la migration */
    private static function updatedVariables(string $type): array
    {
        $ids = ['pri_report_card' => '01PRIDT0700000000000000000', 'pri_annual_report' => '01PRIDT0800000000000000000', 'pp_doc08_unit_report' => '01PUBDT0800000000000000000',
            'pp_doc09_trimester_report' => '01PUBDT0900000000000000000', 'pp_doc10_annual_booklet' => '01PUBDT1000000000000000000'];
        foreach (self::MIGRATIONS as $file) {
            $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            if (preg_match("#UPDATE document_types SET variables = '([^']*)'[^\n]*WHERE id = '" . ($ids[$type] ?? 'x') . "'#", $sql, $m) === 1) {
                return json_decode($m[1], true);
            }
        }

        return [];
    }

    /** @return array{0: string, 1: list<string>} */
    private static function higherTemplate(string $locale): array
    {
        $type = (new DeliberationDocumentTypes())->types()[0];

        return [HigherDocumentCatalog::html($type, $locale), HigherDocumentCatalog::variablesOf($type)];
    }

    /** @return array<string, string> */
    private static function values(string $type, string $locale): array
    {
        $values = match ($type) {
            'pri_report_card', 'pri_annual_report' => SnapshotBuilder::variables(self::primarySnapshot($locale, $type === 'pri_annual_report')),
            'pp_doc08_unit_report', 'pp_doc09_trimester_report', 'pp_doc10_annual_booklet' => PublicReportCards::documentData(self::publicContent($type), $locale, self::publicLayout($locale)),
            'hi_doc09' => TranscriptService::semesterVariables(self::higherData($locale)) + self::higherBranding($locale),
            default => self::secondaryValues($locale, $type === 'secondary_report_annual'),
        };

        return array_map(static fn (mixed $v): string => (string) $v, $values);
    }

    /** @return array<string, mixed> instantané fictif du primaire privé (forme de SnapshotBuilder::build) */
    private static function primarySnapshot(string $locale, bool $annual): array
    {
        $en = $locale === 'en';
        $subjects = [['Français', 'French', 13.5], ['Anglais', 'English', 15.0], ['Mathématiques', 'Mathematics', 12.25], ['Sciences et technologie', 'Science and technology', 14.0],
            ['Histoire et géographie', 'History and geography', 11.75], ['Éducation à la citoyenneté', 'Citizenship education', 16.0], ['Langues et cultures nationales', 'National languages and cultures', 13.0],
            ['Éducation artistique', 'Arts education', 17.0], ['Éducation physique et sportive', 'Physical education', 15.5], ['Informatique', 'ICT', 14.25]];
        $domains = [
            ['Langues', 'Languages', ['Lire un texte simple', 'Écrire une phrase correcte', 'S\'exprimer oralement', 'Comprendre un message en anglais']],
            ['Mathématiques et sciences', 'Mathematics and science', ['Calculer avec les quatre opérations', 'Résoudre un problème', 'Observer un phénomène', 'Mesurer des longueurs']],
            ['Sciences humaines', 'Social studies', ['Se repérer sur une carte', 'Situer un fait dans le temps', 'Respecter les règles de vie']],
            ['Arts et développement personnel', 'Arts and personal development', ['Dessiner et colorier', 'Chanter en groupe', 'Pratiquer une activité physique']],
        ];
        $levels = ['Expert', 'Acquis', 'En cours d\'acquisition', 'Acquis', 'Non acquis'];
        $i = 0;

        return [
            'kind' => $annual ? PrimaryReportCards::ANNUAL : 'term', 'locale' => $locale, 'school' => 'École privée Les Flamboyants (fictive)',
            'class_name' => 'CM1 A', 'level_name' => 'CM1', 'academic_year' => '2026-2027', 'period_label' => $en ? 'Term 1' : 'Trimestre 1',
            'learner' => ['person_id' => 'p-fictif', 'name' => self::HOLDER], 'repeater' => false,
            'numeric' => ['scale' => 20, 'average' => 14.18, 'rank' => 3, 'class_size' => 38,
                'subjects' => array_map(static fn (array $s): array => ['name' => $en ? $s[1] : $s[0], 'average' => $s[2]], $subjects)],
            'show_rank' => true,
            'competencies' => array_map(static function (array $d) use ($en, $levels, &$i): array {
                return ['domain_id' => 'd', 'label' => $en ? $d[1] : $d[0], 'items' => array_map(static function (string $c) use ($levels, &$i): array {
                    return ['competency_id' => 'c', 'code' => 'C', 'label' => $c, 'value' => 'A', 'value_label' => $levels[$i++ % count($levels)]];
                }, $d[2])];
            }, $domains),
            'scale' => [['code' => 'EX', 'label' => 'Expert'], ['code' => 'A', 'label' => $en ? 'Achieved' : 'Acquis'], ['code' => 'ECA', 'label' => $en ? 'In progress' : 'En cours d\'acquisition'], ['code' => 'NA', 'label' => $en ? 'Not achieved' : 'Non acquis']],
            'attendance' => ['absences_half_days' => 3, 'unjustified_half_days' => 1, 'lates' => 2],
            'conduct' => ['code' => 'TB', 'label' => $en ? 'Very good' : 'Très bonne'], 'appraisal' => $en ? 'Good term, keep it up' : 'Bon trimestre, continue ainsi',
            'remediation' => ['flag' => true, 'note' => $en ? 'Reading support on Wednesdays' : 'Soutien en lecture le mercredi', 'sessions' => 4, 'minutes' => 180, 'open_plans' => ['Lecture']],
            'decision' => ['code' => 'admitted', 'label' => $en ? 'Promoted' : 'Admis(e) en classe supérieure'], 'is_final_term' => $annual,
            'letterhead' => self::letterhead($locale),
            'annual' => ['average' => 14.02, 'rank' => 4, 'absences_half_days' => 7, 'terms' => [
                ['period_id' => 't1', 'label' => $en ? 'Term 1' : 'Trimestre 1', 'average' => 14.18], ['period_id' => 't2', 'label' => $en ? 'Term 2' : 'Trimestre 2', 'average' => 13.6],
                ['period_id' => 't3', 'label' => $en ? 'Term 3' : 'Trimestre 3', 'average' => 14.29]]],
        ];
    }

    /** @return array{authority_fr: string, authority_en: string, contacts: string, footer: string} */
    private static function letterhead(string $locale): array
    {
        return [
            'authority_fr' => "Délégation régionale d'exemple\nDélégation départementale d'exemple", 'authority_en' => "Example Regional Delegation\nExample Divisional Delegation",
            'contacts' => "BP 0000 Exempleville · Tél. 600 00 00 00\nexemple.invalid",
            'footer' => $locale === 'en' ? 'Report card issued without erasures or overwriting.' : 'Le bulletin est délivré sans rature ni surcharge.',
        ];
    }

    /** @return array<string, mixed> */
    private static function publicLayout(string $locale): array
    {
        return self::letterhead($locale) + ['scale' => [
            ['code' => 'A', 'label_fr' => 'Acquis', 'label_en' => 'Achieved'], ['code' => 'ECA', 'label_fr' => 'En cours d\'acquisition', 'label_en' => 'In progress'],
            ['code' => 'NA', 'label_fr' => 'Non acquis', 'label_en' => 'Not achieved'],
        ]];
    }

    /** @return array<string, mixed> contenu fictif d'un bulletin de la primaire publique (forme de ReportCardService::content) */
    private static function publicContent(string $type): array
    {
        $kind = ['pp_doc08_unit_report' => 'unit', 'pp_doc09_trimester_report' => 'trimester', 'pp_doc10_annual_booklet' => 'year'][$type];
        $frames = [['Français', 'French', true], ['Anglais', 'English', true], ['Mathématiques', 'Mathematics', true], ['Sciences et technologie', 'Science and technology', false],
            ['Sciences humaines et sociales', 'Social sciences', false], ['Langues et cultures nationales', 'National languages and cultures', false], ['Éducation artistique', 'Arts education', false],
            ['Éducation physique et sportive', 'Physical education', false], ['Développement personnel', 'Personal development', false], ['Technologies de l\'information', 'ICT', false]];
        $mentions = ['A', 'ECA', 'A', 'NA', 'A', 'ECA'];
        $disciplines = [];
        foreach ($frames as $n => [$fr, $en, $key]) {
            $line = ['frame_id' => 'f' . $n, 'discipline_code' => 'D' . $n, 'label_fr' => $fr, 'label_en' => $en, 'mention' => $mentions[$n % 6], 'perfection' => $n % 4 === 0];
            if ($kind !== 'unit') {
                $line['is_key'] = $key;
            }
            if ($kind === 'trimester') {
                $line['units'] = [['unit_no' => 1, 'mention' => $mentions[($n + 1) % 6]], ['unit_no' => 2, 'mention' => $mentions[($n + 2) % 6]], ['unit_no' => 3, 'mention' => $n === 3 ? 'not_assessed' : $mentions[$n % 6]]];
            }
            $disciplines[] = $line;
        }

        return [
            'kind' => $kind, 'year_label' => '2026-2027', 'level_code' => 'CE2', 'class_label' => 'CE2 B', 'period_label_fr' => $kind === 'unit' ? 'Unité 2' : ($kind === 'year' ? 'Année 2026-2027' : 'Trimestre 1'),
            'period_label_en' => $kind === 'unit' ? 'Unit 2' : ($kind === 'year' ? 'Year 2026-2027' : 'Term 1'), 'learner_number' => 'ID-FICTIF-0042', 'birth_date' => null,
            'disciplines' => $disciplines, 'remediation' => $kind === 'year' ? [] : ['Mathématiques, C2 Résoudre un problème simple', 'Français, C4 Lire à haute voix'],
            'appraisal' => 'Élève appliqué(e) ; peut mieux faire en sciences.',
        ];
    }

    /** @return array<string, mixed> données fictives d'un relevé semestriel (forme de TranscriptService::build) */
    private static function higherData(string $locale): array
    {
        $units = [];
        foreach ([['UE INF111', 'Algorithmique et programmation', 6, 13.4, 3.0, 'B', 'acquired'], ['UE INF112', 'Architecture des ordinateurs', 5, 11.2, 2.0, 'C', 'acquired'],
            ['UE MAT113', 'Analyse et algèbre', 6, 9.1, 1.0, 'D', 'compensated'], ['UE INF114', 'Systèmes d\'exploitation', 4, 8.2, 0.0, 'F', 'not_acquired'],
            ['UE LAN115', 'Anglais technique', 3, 14.6, 3.3, 'B+', 'acquired'], ['UE GES116', 'Économie d\'entreprise', 3, 12.0, 2.3, 'C+', 'acquired'],
            ['UE PPE117', 'Projet personnel et professionnel', 3, 15.5, 3.7, 'A-', 'acquired']] as $n => [$code, $name, $credits, $avg, $points, $letter, $status]) {
            $labels = TranscriptService::RESULT_LABELS[$locale];
            $units[] = ['code' => $code, 'name' => $name, 'credits' => $credits, 'average' => $avg, 'letter' => $letter, 'points' => $points, 'status' => $status,
                'result_label' => $labels[$status], 'session' => 'normal', 'session_label' => $locale === 'en' ? 'Regular' : 'Normale',
                'elements' => [['code' => 'EC' . ($n + 1) . '1 Cours magistral', 'credits' => (int) ceil($credits / 2), 'note' => $avg + 0.5], ['code' => 'EC' . ($n + 1) . '2 Travaux pratiques', 'credits' => (int) floor($credits / 2), 'note' => $avg - 0.5]]];
        }

        return [
            'jury_id' => 'JURY-FICTIF-01', 'period_label' => 'Semestre 1', 'session_label' => $locale === 'en' ? 'Regular' : 'Normale', 'academic_year_id' => '2026-2027', 'locale' => $locale,
            'authorization_ref' => 'AUT-FICTIVE-000', 'tutelage' => 'Université d\'exemple (fictive)',
            'student' => ['last_name' => 'MBARGA ESSOMBA', 'first_names' => 'Nadège (fictif)', 'birth_date' => '2006-04-12', 'matricule' => 'IPES-FICTIF-0042'],
            'units' => $units,
            'synthesis' => ['average' => 11.86, 'gpa' => 2.31, 'credits_earned' => 26, 'credits_total' => 30, 'decision' => $locale === 'en' ? 'Semester passed by compensation' : 'Semestre validé par compensation', 'mention' => $locale === 'en' ? 'Fair' : 'Passable', 'rank' => 12, 'class_size' => 48],
        ];
    }

    /** @return array<string, string> */
    private static function higherBranding(string $locale): array
    {
        return ['school_name' => 'École d\'ingénieurs d\'exemple (fictive)', 'school_code' => 'EX', 'school_header' => 'Arrêté d\'autorisation : [N° FICTIF]', 'school_logo_url' => '',
            'school_color' => '#0B5394', 'document_title' => $locale === 'en' ? 'Semester marks transcript' : 'Relevé de notes semestriel', 'program_name' => 'Génie logiciel', 'level_name' => 'Licence 1',
            'issue_place' => 'Exempleville'];
    }

    /**
     * Jeu de valeurs FICTIF du bulletin du secondaire (modèle A), tel que le fournisseur assessment.report_card_variables.secondary
     * (lot E3-A) devra le produire : tableaux JSON pour {{table:x}}, groupes de matières avec ligne TOTAL, rangs.
     *
     * @return array<string, string>
     */
    private static function secondaryValues(string $locale, bool $annual): array
    {
        $en = $locale === 'en';
        $groups = [
            ['1er GROUPE / 1st GROUP', [['Français', 'M. EYENGA (fictif)', 4], ['Anglais', 'Mme TCHOUA (fictive)', 3], ['Histoire', 'M. BELLO (fictif)', 2], ['Géographie', 'M. BELLO (fictif)', 2], ['Éducation à la citoyenneté', 'Mme NJOYA (fictive)', 1]]],
            ['2e GROUPE / 2nd GROUP', [['Mathématiques', 'M. FOUDA (fictif)', 4], ['Physique-chimie-technologie', 'M. NANA (fictif)', 3], ['Sciences de la vie et de la terre', 'Mme ABENA (fictive)', 3], ['Informatique', 'M. KAMGA (fictif)', 2]]],
            ['3e GROUPE / 3rd GROUP', [['Éducation physique et sportive', 'M. ONANA (fictif)', 2], ['Travail manuel', 'Mme EBODE (fictive)', 1], ['Langues nationales', 'M. DJAMEN (fictif)', 1]]],
        ];
        $appr = ['Passable / average', 'Assez bien / fairly good', 'Bien / good', 'Insuffisant / poor'];
        $rows = [];
        $k = 0;
        foreach ($groups as $g => [$title, $subjects]) {
            $rows[] = ['kind' => 'group', 'cells' => [['text' => $title, 'colspan' => 14]]];
            foreach ($subjects as [$name, $teacher, $coef]) {
                $base = 9.0 + (($k * 37) % 70) / 10;
                $marks = [];
                for ($s = 0; $s < 9; ++$s) {
                    $marks[] = number_format($base + (($s * 7 + $k) % 9 - 4) / 4, 2, ',', '');
                }
                $rows[] = [$name . "\n" . $teacher, $coef, ...$marks, number_format($base, 2, ',', ''), ($k % 40 + 1) . 'e', $appr[$k % 4]];
                ++$k;
            }
            $rows[] = ['kind' => 'total', 'cells' => ['TOTAL ' . ($g + 1), array_sum(array_column($subjects, 2)), '11,20', '11,85', '11,52', '12,04', '12,30', '12,17', '11,90', '12,45', '12,18', '11,96', ($g + 5) . 'e', '']];
        }
        $head = [
            [['text' => $en ? 'Subjects and teachers' : 'Matières et noms des professeurs', 'rowspan' => 2], ['text' => 'Coef', 'rowspan' => 2],
                ['text' => $en ? 'TERM 1' : 'TRIMESTRE 1', 'colspan' => 3], ['text' => $en ? 'TERM 2' : 'TRIMESTRE 2', 'colspan' => 3], ['text' => $en ? 'TERM 3' : 'TRIMESTRE 3', 'colspan' => 3],
                ['text' => $en ? "Annual\naverage" : "Moyenne\nannuelle", 'rowspan' => 2], ['text' => $en ? 'Rank' : 'Rang', 'rowspan' => 2], ['text' => $en ? 'Remarks and signatures' : 'Appréciations et signatures', 'rowspan' => 2]],
            ['SEQ1', 'SEQ2', 'TRIM1', 'SEQ3', 'SEQ4', 'TRIM2', 'SEQ5', 'SEQ6', 'TRIM3'],
        ];
        $periods = ['SEQ1', 'SEQ2', 'TRIM1', 'SEQ3', 'SEQ4', 'TRIM2', 'SEQ5', 'SEQ6', 'TRIM3'];
        $json = static fn (array $t): string => json_encode($t, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return [
            'structure' => 'cls-fictive', 'period' => $annual ? ($en ? 'Year 2026-2027' : 'Année 2026-2027') : ($en ? 'Term 1' : 'Trimestre 1'),
            'average' => '12,18', 'rank' => '7e', 'class_size' => '52', 'decision' => 'admitted', 'appraisal' => $en ? 'Fairly good / assez bien' : 'Assez bien / fairly good',
            'secondary_authority_fr' => "Délégation régionale d'exemple\nDélégation départementale d'exemple", 'secondary_authority_en' => "Example Regional Delegation\nExample Divisional Delegation",
            'secondary_school_contacts' => "BP 0000 Exempleville · Tél. 600 00 00 00\nexemple.invalid", 'secondary_footer_note' => $en ? 'No claim is accepted after 15 days.' : 'Aucune réclamation n\'est recevable après 15 jours.',
            'secondary_academic_year' => '2026-2027', 'secondary_class_name' => 'Première C', 'secondary_last_name' => 'MBARGA ESSOMBA', 'secondary_first_names' => 'Nadège (fictif)',
            'secondary_birth_line' => $en ? '12/04/2009 at Exempleville' : '12/04/2009 à Exempleville', 'secondary_matricule' => 'SEC-FICTIF-0042', 'secondary_parent_address' => 'BP 0000 Exempleville · 600 00 00 00',
            'secondary_repeater' => $en ? 'No' : 'Non', 'secondary_head_teacher' => 'M. FOUDA (fictif)',
            'secondary_subjects_table' => $json(['class' => 'compact', 'columns' => [['label' => '', 'width' => 24, 'align' => 'left'], ['label' => '', 'align' => 'center'], ...array_fill(0, 9, ['label' => '', 'align' => 'center']), ['label' => '', 'align' => 'center'], ['label' => '', 'align' => 'center'], ['label' => '', 'width' => 13, 'align' => 'left']], 'head' => $head, 'rows' => $rows]),
            'secondary_summary_table' => $json(['class' => 'compact', 'columns' => [['label' => '', 'align' => 'left'], ...array_map(static fn (string $p): array => ['label' => $p, 'align' => 'center'], $periods)],
                'rows' => [['Total', '512,4', '530,1', '521,3', '540,0', '552,6', '546,3', '535,2', '560,4', '547,8'], [$en ? 'Average' : 'Moy / Aver', '11,39', '11,78', '11,58', '12,00', '12,28', '12,14', '11,89', '12,45', '12,17'], [$en ? 'Rank' : 'Rang / Rank', '9e', '8e', '8e', '7e', '6e', '7e', '8e', '6e', '7e']]]),
            'secondary_class_profile_table' => $json(['class' => 'compact', 'columns' => [['label' => '', 'align' => 'left'], ['label' => 'TRIM1', 'align' => 'center'], ['label' => 'TRIM2', 'align' => 'center'], ['label' => 'TRIM3', 'align' => 'center'], ['label' => $en ? 'Year' : 'Année', 'align' => 'center']],
                'rows' => [[$en ? 'Highest average' : 'Moyenne du 1er', '16,42', '16,80', '17,05', '16,76'], [$en ? 'Lowest average' : 'Moyenne du dernier', '5,12', '5,60', '6,02', '5,58'], [$en ? 'Class average' : 'Moyenne générale', '10,84', '11,02', '11,30', '11,05']]]),
            'secondary_discipline_table' => $json(['class' => 'compact', 'columns' => [['label' => '', 'align' => 'left', 'width' => 75], ['label' => '', 'align' => 'center']],
                'rows' => [[$en ? 'Excused absences (h)' : 'Absences justifiées (h)', '6'], [$en ? 'Unexcused absences (h)' : 'Absences non justifiées (h)', '2'], [$en ? 'Exclusions (days)' : 'Exclusions (jours)', '0'], [$en ? 'Detentions (h)' : 'Consignes (h)', '2'], [$en ? 'Conduct warning' : 'Avertissement conduite', $en ? 'No' : 'Non'], [$en ? 'Conduct reprimand' : 'Blâme conduite', $en ? 'No' : 'Non']]]),
            'secondary_work_table' => $json(['class' => 'compact', 'columns' => [['label' => '', 'align' => 'left', 'width' => 75], ['label' => '', 'align' => 'center']],
                'rows' => [[$en ? 'Roll of honour' : 'Tableau d\'honneur', $en ? 'Yes' : 'Oui'], [$en ? 'Encouragement' : 'Encouragements', $en ? 'Yes' : 'Oui'], [$en ? 'Congratulations' : 'Félicitations', $en ? 'No' : 'Non'], [$en ? 'Work warning' : 'Avertissement travail', $en ? 'No' : 'Non'], [$en ? 'Work reprimand' : 'Blâme travail', $en ? 'No' : 'Non']]]),
            'secondary_council_decision' => $annual ? ($en ? 'Promoted to Upper Sixth (Terminale C)' : 'Admis(e) en classe de Terminale C') : '',
            'secondary_observations' => $en ? 'Steady work, keep it up.' : 'Travail régulier, poursuivre les efforts.', 'secondary_issue_place' => 'Exempleville',
        ];
    }
}
