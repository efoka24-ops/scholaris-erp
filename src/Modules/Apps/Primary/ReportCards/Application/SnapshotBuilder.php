<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\ReportCards\Application;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Councils\Application\CouncilService;
use Scholaris\Modules\Apps\Primary\Remediation\Application\RemediationService;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\ScaleProvider;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Contenu d'un bulletin (copie figée à l'émission, RG-PRI-5) et variables du modèle de document (textes préparés, une ligne par
 * élément). Tout libellé vient des paramètres de l'école (échelles, décisions) ou du référentiel ; le rang n'est jamais produit si
 * `primary.report_card.show_rank` est faux (Q8) ; les moyennes chiffrées n'apparaissent que si le conseil en a (Q3, Q6).
 */
final class SnapshotBuilder
{
    /** Variables ajoutées par la mise en page DOC-BUL : ignorées si un type personnalisé de l'école ne les déclare pas. */
    public const LAYOUT_VARIABLES = [
        'authority_fr', 'authority_en', 'school_contacts', 'footer_note', 'class_size', 'subjects_table', 'competencies_table', 'terms_table',
        'identity_line', 'subjects_detail_table', 'domain_totals_table', 'class_profile_table', 'work_table',
    ];

    public function __construct(
        private readonly Store $store,
        private readonly ClassRoster $classes,
        private readonly CompetencyDirectory $competencies,
        private readonly LevelDirectory $levels,
        private readonly ScaleProvider $scales,
        private readonly PrimarySettings $settings,
        private readonly RemediationService $remediation,
        private readonly RuleCalculator $calculator,
        private readonly EstablishmentDirectory $establishments,
        private readonly OrgUnitTree $tree,
        private readonly BulletinDetails $details,
    ) {
    }

    /** Oublie les résultats de classe mémorisés pour le détail des bulletins (début et fin d'une émission en lot, aperçu). */
    public function flush(): void
    {
        $this->details->flush();
    }

    /** Langue du bulletin : paramètre imposé, sinon langue de la section du niveau (Q1), sinon français. */
    public function locale(string $tenantId, string $establishmentId, ?string $levelId): string
    {
        $forced = $this->settings->string($tenantId, $establishmentId, 'primary.report_card.locale');
        if ($forced === 'fr' || $forced === 'en') {
            return $forced;
        }
        $profile = $levelId === null ? null : $this->levels->profile($tenantId, $establishmentId, $levelId);

        return $profile !== null && $profile->language === 'en' ? 'en' : 'fr';
    }

    /**
     * @param array<string, mixed> $council
     * @param array<string, mixed> $line ligne brute (pri_council_lines)
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $establishmentId, array $council, array $line, string $kind): array
    {
        $l = CouncilService::lineResource($line);
        $levelId = $council['level_structure_id'] === null ? null : (string) $council['level_structure_id'];
        $locale = $this->locale($tenantId, $establishmentId, $levelId);
        $classId = (string) $council['class_structure_id'];
        $class = $this->tree->structure($tenantId, $establishmentId, $classId);
        $level = $levelId === null ? null : $this->tree->structure($tenantId, $establishmentId, $levelId);
        $names = $this->classes->names($tenantId, [$l['person_id']]);
        $showRank = $this->settings->bool($tenantId, $establishmentId, 'primary.report_card.show_rank');
        $rules = $this->calculator->rulesFor($tenantId, $establishmentId, (string) $council['academic_year_id'], $classId);
        $conductLabel = null;
        foreach ($this->scales->conduct($tenantId, $establishmentId) as $level2) {
            if ($level2['code'] === $l['conduct_code']) {
                $conductLabel = $locale === 'en' ? $level2['label_en'] : $level2['label_fr'];
            }
        }
        $decision = $l['decision'] === null ? null : ['code' => $l['decision'], 'label' => $this->settings->label($tenantId, $establishmentId, 'primary.council.decision_labels', $l['decision'], $locale)];
        $remediation = $this->remediation->learnerSummary($tenantId, $establishmentId, $l['person_id'], (string) $council['starts_on'], (string) $council['ends_on']);
        $establishment = $this->establishments->find($tenantId, $establishmentId);

        $snapshot = [
            'kind' => $kind, 'locale' => $locale,
            'school' => $establishment === null ? null : $establishment->name,
            'class_name' => $class === null ? '' : $class->name, 'level_name' => $level === null ? '' : $level->name,
            'academic_year' => (string) ($council['year_label'] ?? $council['academic_year_id']), 'period_label' => (string) $council['period_label'],
            'learner' => ['person_id' => $l['person_id'], 'name' => $names[$l['person_id']] ?? $l['person_id']],
            'repeater' => $l['repeater'],
            'numeric' => [
                'scale' => $rules->scale, 'average' => $l['average'], 'rank' => $showRank ? $l['rank'] : null, 'class_size' => $showRank ? $l['class_size'] : null,
                'subjects' => array_values(array_filter($l['subjects'], static fn (mixed $s): bool => is_array($s))),
            ],
            'show_rank' => $showRank,
            'competencies' => $this->competencyDetail($tenantId, $establishmentId, $council, $l['person_id'], $locale),
            'scale' => array_map(static fn (array $s): array => ['code' => $s['code'], 'label' => $locale === 'en' ? $s['label_en'] : $s['label_fr']], $this->scales->qualitative($tenantId, $establishmentId)),
            'attendance' => ['absences_half_days' => $l['absences_half_days'], 'unjustified_half_days' => $l['unjustified_half_days'], 'lates' => $l['lates']],
            'conduct' => $l['conduct_code'] === null ? null : ['code' => $l['conduct_code'], 'label' => $conductLabel ?? $l['conduct_code']],
            'appraisal' => $l['appraisal'],
            'remediation' => ['flag' => $l['remediation_flag'], 'note' => $l['remediation_note']] + $remediation,
            'decision' => $decision,
            'is_final_term' => (int) $council['is_final_term'] === 1,
            'letterhead' => $this->letterhead($tenantId, $establishmentId, $locale),
        ];
        // Bulletin complet : détail par matière, domaines, profil de classe, travail (trimestre) ; identité seule au récapitulatif annuel.
        $snapshot['detail'] = $kind === ReportCardService::ANNUAL
            ? ['identity' => $this->details->identity($tenantId, $establishmentId, $l['person_id'])]
            : $this->details->build($tenantId, $establishmentId, $council, $l['person_id']);
        if ($kind === ReportCardService::ANNUAL) {
            $snapshot['annual'] = [
                'average' => $l['annual_average'], 'rank' => $showRank ? $l['annual_rank'] : null, 'terms' => $l['terms'],
                'absences_half_days' => $this->yearAbsences($tenantId, $establishmentId, $council, $l['person_id']),
            ];
        }

        return $snapshot;
    }

    /**
     * Variables du modèle (valeurs simples, déjà localisées).
     *
     * @param array<string, mixed> $s
     * @return array<string, scalar|null>
     */
    public static function variables(array $s): array
    {
        $en = $s['locale'] === 'en';
        $fmt = static fn (mixed $v): string => is_int($v) || is_float($v) ? number_format((float) $v, 2, $en ? '.' : ',', '') : '';
        $scale = $fmt($s['numeric']['scale']);
        $scaleLabel = is_int($s['numeric']['scale']) || (is_float($s['numeric']['scale']) && floor($s['numeric']['scale']) === $s['numeric']['scale']) ? (string) (int) $s['numeric']['scale'] : $scale;
        $rankLine = static function (mixed $rank, mixed $size) use ($en): string {
            if (!is_int($rank)) {
                return '';
            }

            return $en ? sprintf('Rank: %d of %s', $rank, (string) $size) : sprintf('Rang : %d%s sur %s', $rank, $rank === 1 ? 'er' : 'e', (string) $size);
        };
        $remediation = [];
        if ((int) $s['remediation']['sessions'] > 0) {
            $remediation[] = ($en ? 'Sessions attended: ' : 'Séances suivies : ') . $s['remediation']['sessions'] . ' (' . $s['remediation']['minutes'] . ' min)';
        }
        if ($s['remediation']['open_plans'] !== []) {
            $remediation[] = ($en ? 'Individual follow-up: ' : 'Suivi individualisé : ') . implode(', ', $s['remediation']['open_plans']);
        }
        if (is_string($s['remediation']['note']) && $s['remediation']['note'] !== '') {
            $remediation[] = $s['remediation']['note'];
        }
        $remediationBlock = $remediation === [] ? ($en ? 'None' : 'Aucune') : implode("\n", $remediation);
        $decision = $s['decision'] === null ? '—' : (string) $s['decision']['label'];
        $letterhead = is_array($s['letterhead'] ?? null) ? $s['letterhead'] : [];
        $common = [
            'class_name' => (string) $s['class_name'], 'level_name' => (string) $s['level_name'], 'academic_year' => (string) $s['academic_year'],
            'appraisal' => (string) ($s['appraisal'] ?? ''), 'decision' => $decision, 'remediation_block' => $remediationBlock,
            // En-tête et pied du modèle (version DOC-BUL) : paramètre de l'école, jamais un libellé officiel inventé.
            'authority_fr' => (string) ($letterhead['authority_fr'] ?? ''), 'authority_en' => (string) ($letterhead['authority_en'] ?? ''),
            'school_contacts' => (string) ($letterhead['contacts'] ?? ''), 'footer_note' => (string) ($letterhead['footer'] ?? ''),
            'class_size' => is_int($s['numeric']['class_size'] ?? null) ? (string) $s['numeric']['class_size'] : '',
            'identity_line' => self::identityLine(is_array($s['detail']['identity'] ?? null) ? $s['detail']['identity'] : [], $en),
        ];
        // Libellés bilingues des tableaux (langue du bulletin d'abord) ; l'en-tête du document est bilingue comme le modèle papier.
        $bi = static fn (string $fr, string $english, string $glue = ' / '): string => $en ? $english . $glue . $fr : $fr . $glue . $english;
        $none = static fn (int $span): array => ['kind' => 'muted', 'cells' => [['text' => '—', 'colspan' => $span, 'align' => 'center']]];
        if ($s['kind'] === ReportCardService::ANNUAL) {
            $terms = array_map(static fn (array $t): string => $t['label'] . ' : ' . ($fmt($t['average']) === '' ? '—' : $fmt($t['average'])), $s['annual']['terms']);
            $average = $fmt($s['annual']['average']);
            $termRows = array_map(static fn (array $t): array => [(string) $t['label'], $fmt($t['average']) === '' ? '—' : $fmt($t['average'])], $s['annual']['terms']);
            if ($average !== '') {
                $termRows[] = ['kind' => 'total', 'cells' => [$bi('MOYENNE ANNUELLE', 'ANNUAL AVERAGE'), $average . ' / ' . $scaleLabel]];
            }

            return $common + [
                'terms_block' => $terms === [] ? '—' : implode("\n", $terms),
                'terms_table' => self::table([
                    'columns' => [['label' => $bi('Trimestres', 'Terms'), 'width' => 60], ['label' => $bi('Moyenne', 'Average') . ' (/' . $scaleLabel . ')', 'align' => 'center']],
                    'rows' => $termRows === [] ? [$none(2)] : $termRows,
                ]),
                'annual_average_line' => $average === '' ? '' : ($en ? 'Annual average: ' : 'Moyenne annuelle : ') . $average . ' / ' . $scale,
                'rank_line' => $rankLine($s['annual']['rank'], $s['numeric']['class_size']),
                'absences' => (string) $s['annual']['absences_half_days'],
            ];
        }
        $subjects = [];
        $subjectRows = [];
        foreach ($s['numeric']['subjects'] as $subject) {
            $value = $fmt($subject['average'] ?? null);
            $subjects[] = ($subject['name'] ?? $subject['code'] ?? '') . ' : ' . ($value === '' ? '—' : $value . ' / ' . $scale);
            $subjectRows[] = [(string) ($subject['name'] ?? $subject['code'] ?? ''), $value === '' ? '—' : $value];
        }
        $competencies = [];
        $competencyRows = [];
        foreach ($s['competencies'] as $domain) {
            $competencies[] = (string) $domain['label'];
            $competencyRows[] = ['kind' => 'group', 'cells' => [['text' => (string) $domain['label'], 'colspan' => 2]]];
            foreach ($domain['items'] as $item) {
                $competencies[] = '  · ' . $item['label'] . ' : ' . ($item['value_label'] ?? '—');
                $competencyRows[] = [(string) $item['label'], (string) ($item['value_label'] ?? '—')];
            }
        }
        $average = $fmt($s['numeric']['average']);
        if ($subjectRows !== [] && $average !== '') {
            $subjectRows[] = ['kind' => 'total', 'cells' => [$bi('MOYENNE GÉNÉRALE', 'OVERALL AVERAGE'), $average]];
        }

        return $common + [
            'period_label' => (string) $s['period_label'],
            'average_line' => $average === '' ? '' : ($en ? 'Overall average: ' : 'Moyenne générale : ') . $average . ' / ' . $scale,
            'rank_line' => $rankLine($s['numeric']['rank'], $s['numeric']['class_size']),
            'conduct' => $s['conduct'] === null ? '—' : (string) $s['conduct']['label'],
            'absences' => (string) $s['attendance']['absences_half_days'], 'unjustified_absences' => (string) $s['attendance']['unjustified_half_days'],
            'lates' => (string) $s['attendance']['lates'], 'repeater' => $s['repeater'] ? ($en ? '(repeater)' : '(redoublant)') : '',
            'subjects_block' => $subjects === [] ? '—' : implode("\n", $subjects),
            'competencies_block' => $competencies === [] ? '—' : implode("\n", $competencies),
            'subjects_table' => self::table([
                'columns' => [['label' => $bi('Matières', 'Subjects'), 'width' => 70], ['label' => $bi('Moyenne', 'Average') . ' (/' . $scaleLabel . ')', 'align' => 'center']],
                'rows' => $subjectRows === [] ? [$none(2)] : $subjectRows,
            ]),
            'competencies_table' => self::table([
                'columns' => [['label' => $bi('Domaines et compétences', 'Areas and competencies'), 'width' => 70], ['label' => $bi('Appréciation', 'Assessment'), 'align' => 'center']],
                'rows' => $competencyRows === [] ? [$none(2)] : $competencyRows,
            ]),
            'scale_legend' => ($en ? 'Scale: ' : 'Échelle : ') . implode(', ', array_map(static fn (array $x): string => (string) $x['label'], $s['scale'])),
        ] + self::detailVariables(is_array($s['detail'] ?? null) ? $s['detail'] : [], $s, $en);
    }

    /**
     * @param array<string, mixed> $identity
     */
    private static function identityLine(array $identity, bool $en): string
    {
        $parts = [];
        $birth = $identity['birth_date'] ?? null;
        if (is_string($birth) && $birth !== '') {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $birth);
            $parts[] = ($en ? 'Born on ' : 'Né(e) le ') . ($date === false ? $birth : $date->format($en ? 'Y-m-d' : 'd/m/Y'));
        }
        $matricule = $identity['matricule'] ?? null;
        if (is_string($matricule) && $matricule !== '') {
            $parts[] = ($en ? 'Reg. no. ' : 'Matricule ') . $matricule;
        }

        return implode(' · ', $parts);
    }

    /**
     * Tableaux du bulletin complet (modèle B) : une colonne n'existe que si la donnée existe (SEQ, COEF, TOTAL, RANG), une donnée absente
     * n'est jamais remplacée par une valeur inventée.
     *
     * @param array<string, mixed> $detail
     * @param array<string, mixed> $s
     * @return array<string, string>
     */
    private static function detailVariables(array $detail, array $s, bool $en): array
    {
        $num = static fn (mixed $v): string => is_int($v) || is_float($v) ? number_format((float) $v, 2, $en ? '.' : ',', '') : '—';
        $coef = static fn (mixed $v): string => is_int($v) || is_float($v) ? rtrim(rtrim(number_format((float) $v, 2, $en ? '.' : ',', ''), '0'), $en ? '.' : ',') : '—';
        $bi = static fn (string $fr, string $english): string => $en ? $english . ' / ' . $fr : $fr . ' / ' . $english;
        $none = static fn (int $span): array => ['kind' => 'muted', 'cells' => [['text' => '—', 'colspan' => $span, 'align' => 'center']]];
        $encode = static fn (array $t): string => json_encode($t, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $sequences = is_array($detail['sequences'] ?? null) ? $detail['sequences'] : [];
        $subjects = is_array($detail['subjects'] ?? null) ? $detail['subjects'] : [];
        $hasCoefficient = array_filter($subjects, static fn (array $x): bool => $x['coefficient'] !== null) !== [];
        $hasRank = array_filter($subjects, static fn (array $x): bool => $x['rank'] !== null) !== [];

        $columns = [['label' => $bi('Matières', 'Subjects'), 'width' => 34]];
        foreach ($sequences as $sequence) {
            $columns[] = ['label' => (string) $sequence['label'], 'align' => 'center'];
        }
        $columns[] = ['label' => 'MOY', 'align' => 'center'];
        if ($hasCoefficient) {
            $columns[] = ['label' => 'COEF', 'align' => 'center'];
            $columns[] = ['label' => 'TOTAL', 'align' => 'center'];
        }
        if ($hasRank) {
            $columns[] = ['label' => $bi('RANG', 'RANK'), 'align' => 'center'];
        }
        $rows = [];
        $coefficientSum = 0.0;
        $totalSum = 0.0;
        foreach ($subjects as $subject) {
            $row = [(string) $subject['name']];
            foreach ($subject['sequences'] as $value) {
                $row[] = $num($value);
            }
            $row[] = $num($subject['average']);
            if ($hasCoefficient) {
                $row[] = $coef($subject['coefficient']);
                $row[] = $num($subject['total']);
                if ($subject['total'] !== null) {
                    $coefficientSum += (float) $subject['coefficient'];
                    $totalSum += (float) $subject['total'];
                }
            }
            if ($hasRank) {
                $row[] = is_int($subject['rank']) ? (string) $subject['rank'] : '—';
            }
            $rows[] = $row;
        }
        if ($rows !== []) {
            $total = [['text' => $bi('MOYENNE GÉNÉRALE', 'OVERALL AVERAGE'), 'colspan' => 1 + count($sequences)], $num($s['numeric']['average'])];
            if ($hasCoefficient) {
                $total[] = $coef($coefficientSum);
                $total[] = $num(round($totalSum, 2));
            }
            if ($hasRank) {
                $total[] = is_int($s['numeric']['rank'] ?? null) ? (string) $s['numeric']['rank'] : '—';
            }
            $rows[] = ['kind' => 'total', 'cells' => $total];
        }

        $domains = is_array($detail['domains'] ?? null) ? $detail['domains'] : [];
        $domainRows = array_map(static fn (array $d): array => [(string) ($en ? $d['label_en'] : $d['label_fr']), $coef($d['coefficient']), $num($d['total']), $num($d['average'])], $domains);

        $profile = is_array($detail['class_profile'] ?? null) ? $detail['class_profile'] : null;
        $profileRows = $profile === null ? [] : [
            [$bi('Élèves à la moyenne de passage', 'Pupils at or above the pass mark') . ' (≥ ' . $coef($profile['pass_mark']) . ')', $profile['above_pass'] . ' / ' . $profile['size']],
            [$bi('Moyenne de la classe', 'Class average'), $num($profile['class_average'])],
            [$bi('Pourcentage de réussite', 'Success rate'), number_format((float) $profile['success_rate'], 1, $en ? '.' : ',', '') . ' %'],
            [$bi('Moyenne maximale', 'Highest average'), $num($profile['max'])],
            [$bi('Moyenne minimale', 'Lowest average'), $num($profile['min'])],
        ];

        $work = is_array($detail['work'] ?? null) ? $detail['work'] : [];
        $labels = [
            'honour_roll' => $bi('Tableau d\'honneur', 'Honour roll'), 'congratulations' => $bi('Félicitations', 'Congratulations'), 'encouragements' => $bi('Encouragements', 'Encouragements'),
            'warning' => $bi('Avertissement', 'Warning'), 'blame' => $bi('Blâme', 'Reprimand'),
        ];
        $workRows = [];
        foreach ($labels as $code => $label) {
            if (array_key_exists($code, $work)) {
                $workRows[] = [$label, $work[$code] === true ? ($en ? 'Yes' : 'Oui') : ($en ? 'No' : 'Non')];
            }
        }

        return [
            'subjects_detail_table' => $encode(['columns' => $columns, 'rows' => $rows === [] ? [$none(count($columns))] : $rows]),
            'domain_totals_table' => $encode([
                'columns' => [['label' => $bi('Totaux par domaine', 'Totals by area'), 'width' => 46], ['label' => 'COEF', 'align' => 'center'], ['label' => 'TOTAL', 'align' => 'center'], ['label' => 'MOY', 'align' => 'center']],
                'rows' => $domainRows === [] ? [$none(4)] : $domainRows,
            ]),
            'class_profile_table' => $encode([
                'columns' => [['label' => $bi('Profil de la classe', 'Class profile'), 'width' => 70], ['label' => '', 'align' => 'center']],
                'rows' => $profileRows === [] ? [$none(2)] : $profileRows,
            ]),
            'work_table' => $encode([
                'columns' => [['label' => $bi('Travail', 'Work'), 'width' => 70], ['label' => '', 'align' => 'center']],
                'rows' => $workRows === [] ? [$none(2)] : $workRows,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $council
     * @return list<array{domain_id: string, label: string, items: list<array{competency_id: string, code: string, label: string, value: ?string, value_label: ?string}>}>
     */
    private function competencyDetail(string $tenantId, string $establishmentId, array $council, string $personId, string $locale): array
    {
        $marks = [];
        foreach ([...CouncilService::children($council), (string) $council['period_id']] as $periodId) {
            foreach ($this->store->select('pri_competency_marks', $tenantId, $establishmentId, ['person_id' => $personId, 'period_id' => $periodId], 'competency_id', 2000) as $row) {
                $marks[(string) $row['competency_id']] = $row['scale_code'] !== null ? (string) $row['scale_code'] : ($row['absence_code'] !== null ? (string) $row['absence_code'] : null);
            }
        }
        if ($marks === []) {
            return [];
        }
        $labels = [];
        foreach ($this->scales->qualitative($tenantId, $establishmentId) as $s) {
            $labels[$s['code']] = $locale === 'en' ? $s['label_en'] : $s['label_fr'];
        }
        $infos = $this->competencies->findMany($tenantId, $establishmentId, array_keys($marks));
        $domains = $this->competencies->domainsOf($tenantId, $establishmentId, (string) $council['academic_year_id']);
        $grouped = [];
        foreach ($infos as $id => $info) {
            $grouped[$info->domainId][] = ['info' => $info, 'value' => $marks[$id] ?? null];
        }
        uksort($grouped, static fn (string $a, string $b): int => ($domains[$a]['sort_order'] ?? 0) <=> ($domains[$b]['sort_order'] ?? 0));
        $out = [];
        foreach ($grouped as $domainId => $items) {
            usort($items, static fn (array $a, array $b): int => $a['info']->sortOrder <=> $b['info']->sortOrder);
            $domain = $domains[$domainId] ?? null;
            $out[] = [
                'domain_id' => $domainId,
                'label' => $domain === null ? '' : ($locale === 'en' && ($domain['label_en'] ?? null) !== null ? (string) $domain['label_en'] : $domain['label_fr']),
                'items' => array_map(static fn (array $i): array => [
                    'competency_id' => $i['info']->id, 'code' => $i['info']->code, 'label' => $i['info']->label($locale),
                    'value' => $i['value'], 'value_label' => $i['value'] === null ? null : ($labels[$i['value']] ?? $i['value']),
                ], $items),
            ];
        }

        return $out;
    }

    /**
     * Tableau structuré pour `{{table:x}}` du socle Documents (JSON : le moteur produit le HTML et échappe chaque cellule).
     *
     * @param array<string, mixed> $table
     */
    private static function table(array $table): string
    {
        return json_encode($table, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * En-tête et pied paramétrables (`primary.report_card.letterhead`) : délégations, contacts de l'école, mention de bas de page.
     *
     * @return array{authority_fr: string, authority_en: string, contacts: string, footer: string}
     */
    private function letterhead(string $tenantId, string $establishmentId, string $locale): array
    {
        $raw = $this->settings->raw($tenantId, $establishmentId, 'primary.report_card.letterhead');
        $value = is_array($raw) ? $raw : [];
        $text = static fn (string $key): string => is_string($value[$key] ?? null) ? trim((string) $value[$key]) : '';

        return [
            'authority_fr' => $text('authority_fr'), 'authority_en' => $text('authority_en'), 'contacts' => $text('contacts'),
            'footer' => $text($locale === 'en' ? 'footer_en' : 'footer_fr'),
        ];
    }

    /** @param array<string, mixed> $council */
    private function yearAbsences(string $tenantId, string $establishmentId, array $council, string $personId): int
    {
        $total = 0;
        foreach ($this->store->select(CouncilService::COUNCILS, $tenantId, $establishmentId, ['class_structure_id' => (string) $council['class_structure_id'], 'academic_year_id' => (string) $council['academic_year_id']], 'id', 50) as $term) {
            foreach ($this->store->select(CouncilService::LINES, $tenantId, $establishmentId, ['council_id' => (string) $term['id'], 'person_id' => $personId], 'id', 1) as $line) {
                $total += (int) $line['absences_half_days'];
            }
        }

        return $total;
    }
}
