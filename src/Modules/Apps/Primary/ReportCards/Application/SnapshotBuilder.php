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
    ) {
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
        ];
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
        $common = [
            'class_name' => (string) $s['class_name'], 'level_name' => (string) $s['level_name'], 'academic_year' => (string) $s['academic_year'],
            'appraisal' => (string) ($s['appraisal'] ?? ''), 'decision' => $decision, 'remediation_block' => $remediationBlock,
        ];
        if ($s['kind'] === ReportCardService::ANNUAL) {
            $terms = array_map(static fn (array $t): string => $t['label'] . ' : ' . ($fmt($t['average']) === '' ? '—' : $fmt($t['average'])), $s['annual']['terms']);
            $average = $fmt($s['annual']['average']);

            return $common + [
                'terms_block' => $terms === [] ? '—' : implode("\n", $terms),
                'annual_average_line' => $average === '' ? '' : ($en ? 'Annual average: ' : 'Moyenne annuelle : ') . $average . ' / ' . $scale,
                'rank_line' => $rankLine($s['annual']['rank'], $s['numeric']['class_size']),
                'absences' => (string) $s['annual']['absences_half_days'],
            ];
        }
        $subjects = [];
        foreach ($s['numeric']['subjects'] as $subject) {
            $value = $fmt($subject['average'] ?? null);
            $subjects[] = ($subject['name'] ?? $subject['code'] ?? '') . ' : ' . ($value === '' ? '—' : $value . ' / ' . $scale);
        }
        $competencies = [];
        foreach ($s['competencies'] as $domain) {
            $competencies[] = (string) $domain['label'];
            foreach ($domain['items'] as $item) {
                $competencies[] = '  · ' . $item['label'] . ' : ' . ($item['value_label'] ?? '—');
            }
        }
        $average = $fmt($s['numeric']['average']);

        return $common + [
            'period_label' => (string) $s['period_label'],
            'average_line' => $average === '' ? '' : ($en ? 'Overall average: ' : 'Moyenne générale : ') . $average . ' / ' . $scale,
            'rank_line' => $rankLine($s['numeric']['rank'], $s['numeric']['class_size']),
            'conduct' => $s['conduct'] === null ? '—' : (string) $s['conduct']['label'],
            'absences' => (string) $s['attendance']['absences_half_days'], 'unjustified_absences' => (string) $s['attendance']['unjustified_half_days'],
            'lates' => (string) $s['attendance']['lates'], 'repeater' => $s['repeater'] ? ($en ? '(repeater)' : '(redoublant)') : '',
            'subjects_block' => $subjects === [] ? '—' : implode("\n", $subjects),
            'competencies_block' => $competencies === [] ? '—' : implode("\n", $competencies),
            'scale_legend' => ($en ? 'Scale: ' : 'Échelle : ') . implode(', ', array_map(static fn (array $x): string => (string) $x['label'], $s['scale'])),
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
