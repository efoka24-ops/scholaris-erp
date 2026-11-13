<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Councils\Application;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Valeurs calculées d'une ligne de conseil (aucune règle officielle ici : tout vient des règles du niveau et des paramètres) :
 *  - moyennes et rang chiffrés du trimestre par le moteur du socle (ClassResults), si `primary.report_card.numeric_averages` ;
 *  - au dernier trimestre : moyennes des trimestres de l'année, décision annuelle par RuleCalculator::decideYear (stratégie et
 *    poids des règles), rang annuel avec ex æquo ; proposition « fin de cycle » au niveau final, sinon admis / redouble selon la
 *    stratégie (ou la note de passage des règles), aucune proposition sans moyenne (le conseil décide) ;
 *  - absences et retards du trimestre (Attendance ; une séance = une demi-journée en mode `day_part`) ;
 *  - conduite saisie, synthèse des compétences (nombre d'évaluations par niveau de l'échelle), suivi de remédiation ouvert, redoublant.
 */
final class CouncilLineBuilder
{
    public function __construct(
        private readonly Store $store,
        private readonly PrimarySettings $settings,
        private readonly ClassResults $results,
        private readonly RuleCalculator $calculator,
        private readonly AttendanceSummaryProvider $attendance,
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly LevelDirectory $levels,
    ) {
    }

    /**
     * @param array<string, mixed> $council
     * @param list<string> $personIds
     * @return array{lines: array<string, array<string, scalar|null>>, numeric: bool, rule_version: ?int}
     */
    public function build(string $tenantId, string $establishmentId, array $council, array $personIds): array
    {
        $classId = (string) $council['class_structure_id'];
        $periodId = (string) $council['period_id'];
        $numeric = [];
        $ruleVersion = null;
        $numericEnabled = $this->settings->bool($tenantId, $establishmentId, 'primary.report_card.numeric_averages');
        if ($numericEnabled) {
            foreach ($this->results->compute($tenantId, $establishmentId, $classId, $periodId, (string) $council['period_label']) as $row) {
                $numeric[$row['person_id']] = $row;
                $ruleVersion = $row['rule_version'];
            }
        }
        $annual = (int) $council['is_final_term'] === 1 ? $this->annual($tenantId, $establishmentId, $council, $numericEnabled) : [];

        $conduct = [];
        foreach ($this->store->select('pri_conduct_marks', $tenantId, $establishmentId, ['class_structure_id' => $classId, 'period_id' => $periodId], 'person_id', 5000) as $row) {
            $conduct[(string) $row['person_id']] = (string) $row['scale_code'];
        }
        $competencies = [];
        foreach ([$periodId, ...CouncilService::children($council)] as $markPeriod) {
            foreach ($this->store->select('pri_competency_marks', $tenantId, $establishmentId, ['class_structure_id' => $classId, 'period_id' => $markPeriod], 'person_id', 20000) as $row) {
                $code = $row['scale_code'] !== null ? (string) $row['scale_code'] : ($row['absence_code'] !== null ? (string) $row['absence_code'] : null);
                if ($code !== null) {
                    $competencies[(string) $row['person_id']][$code] = ($competencies[(string) $row['person_id']][$code] ?? 0) + 1;
                }
            }
        }
        $plans = [];
        foreach ($this->store->select('pri_remediation_plans', $tenantId, $establishmentId, ['class_structure_id' => $classId], 'person_id', 5000, "status <> 'resolved'") as $row) {
            $plans[(string) $row['person_id']] = true;
        }
        $levelId = $council['level_structure_id'] === null ? null : (string) $council['level_structure_id'];
        $profile = $levelId === null ? null : $this->levels->profile($tenantId, $establishmentId, $levelId);
        $finalLevel = $profile !== null && $profile->isFinalLevel;

        $lines = [];
        $anyNumeric = false;
        foreach ($personIds as $personId) {
            $result = $numeric[$personId] ?? null;
            $summary = $this->attendance->forLearner($tenantId, $establishmentId, $personId, (string) $council['starts_on'], (string) $council['ends_on']);
            $year = $annual[$personId] ?? null;
            $anyNumeric = $anyNumeric || ($result !== null && $result['average'] !== null);
            $proposal = null;
            if ((int) $council['is_final_term'] === 1) {
                $proposal = $finalLevel ? 'cycle_end' : ($year['decision'] ?? null);
            }
            $lines[$personId] = [
                'average_value' => $result === null || $result['average'] === null ? null : (string) $result['average'],
                'rank_in_class' => $result === null || $result['average'] === null ? null : $result['rank'],
                'class_size' => $result === null ? count($personIds) : $result['class_size'],
                'subjects_json' => $result === null ? null : json_encode($result['subjects'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'annual_average' => $year === null || $year['average'] === null ? null : (string) $year['average'],
                'annual_rank' => $year['rank'] ?? null,
                'terms_json' => $year === null ? null : json_encode($year['terms'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'absences_half_days' => $summary->absences,
                'unjustified_half_days' => $summary->unjustifiedAbsences,
                'lates' => $summary->lates,
                'conduct_code' => $conduct[$personId] ?? null,
                'competency_summary' => json_encode($competencies[$personId] ?? (object) [], JSON_THROW_ON_ERROR),
                'appraisal' => $result === null ? null : $result['mention'],
                'remediation_flag' => isset($plans[$personId]) ? 1 : 0,
                'repeater' => $this->isRepeater($tenantId, $establishmentId, $personId, $classId, (string) $council['academic_year_id']) ? 1 : 0,
                'proposal' => $proposal,
            ];
        }

        return ['lines' => $lines, 'numeric' => $anyNumeric, 'rule_version' => $ruleVersion];
    }

    /**
     * Récapitulatif annuel : moyennes des trimestres (conseils de la classe pour l'année), décision par la stratégie des règles.
     *
     * @param array<string, mixed> $council
     * @return array<string, array{average: ?float, rank: ?int, decision: ?string, terms: list<array{period_id: string, label: string, average: ?float}>}>
     */
    private function annual(string $tenantId, string $establishmentId, array $council, bool $numericEnabled): array
    {
        $classId = (string) $council['class_structure_id'];
        $terms = $this->store->select(CouncilService::COUNCILS, $tenantId, $establishmentId, ['class_structure_id' => $classId, 'academic_year_id' => (string) $council['academic_year_id'], 'period_kind' => 'trimester'], 'term_ordinal', 50);
        $known = array_map(static fn (array $c): string => (string) $c['period_id'], $terms);
        if (!in_array((string) $council['period_id'], $known, true)) {
            $terms[] = $council;
        }
        $byPerson = [];
        foreach ($terms as $term) {
            $rows = $numericEnabled ? $this->results->compute($tenantId, $establishmentId, $classId, (string) $term['period_id'], (string) $term['period_label']) : [];
            foreach ($rows as $row) {
                $units = [];
                foreach ($row['subjects'] as $subject) {
                    $units[$subject['subject_id']] = ['average' => $subject['average']];
                }
                $byPerson[$row['person_id']]['summaries'][] = ['period_id' => (string) $term['period_id'], 'average' => $row['average'], 'credits_earned' => 0, 'credits_total' => 0, 'units' => $units];
                $byPerson[$row['person_id']]['terms'][] = ['period_id' => (string) $term['period_id'], 'label' => (string) $term['period_label'], 'average' => $row['average']];
            }
        }
        if ($byPerson === []) {
            return [];
        }
        $rules = $this->calculator->rulesFor($tenantId, $establishmentId, (string) $council['academic_year_id'], $classId);
        $out = [];
        $averages = [];
        foreach ($byPerson as $personId => $data) {
            $decided = $this->calculator->decideYear($rules, $data['summaries'], ['is_final_level' => false]);
            $average = $decided['average'];
            $code = $decided['extras']['decision_code'] ?? null;
            if (!is_string($code) || !in_array($code, CouncilService::DECISIONS, true)) {
                $code = $average === null ? null : ($average >= $rules->passMark ? 'admitted' : 'adjourned');
            }
            $out[(string) $personId] = ['average' => $average, 'rank' => null, 'decision' => $code, 'terms' => $data['terms']];
            $averages[(string) $personId] = $average;
        }
        foreach ($this->calculator->ranks($averages) as $personId => $rank) {
            if (isset($out[(string) $personId])) {
                $out[(string) $personId]['rank'] = $rank;
            }
        }

        return $out;
    }

    private function isRepeater(string $tenantId, string $establishmentId, string $personId, string $classId, string $yearId): bool
    {
        $level = $this->tree->levelOf($tenantId, $establishmentId, $classId);
        if ($level === null) {
            return false;
        }
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->academicYearId !== $yearId && $enrollment->structureId !== null
                && in_array($enrollment->status, ['active', 'conditional', 'graduated', 'transferred'], true)
                && $this->tree->levelOf($tenantId, $establishmentId, $enrollment->structureId) === $level) {
                return true;
            }
        }

        return false;
    }
}
