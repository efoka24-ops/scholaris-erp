<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Application;

use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\WorkProposals;
use Scholaris\Modules\Apps\Secondary\Discipline\Contracts\ConductRecord;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\RepeaterRoster;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\TeachingAssignments;
use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Variables `secondary_*` des bulletins du secondaire (T904, modèle A de DOC-BUL : types secondary_report_trimester et
 * secondary_report_annual). Tout vient des données enregistrées, rien n'est inventé :
 *  - grand tableau : une ligne par matière (coefficient versionné de Setup, enseignant de l'emploi du temps), une colonne par période
 *    de l'année (SEQ1, SEQ2, TRIM1, ... lues dans l'arbre des périodes) jusqu'à la période du bulletin, moyenne annuelle (bulletin
 *    annuel), rang dans la matière, appréciation (mentions des règles) ; matières regroupées par le paramètre
 *    `secondary.report_card.subject_groups` (sinon un seul groupe) avec une ligne TOTAL par groupe (moyenne pondérée et rang) ;
 *  - récapitulatif (total des points, moyenne, rang par période), profil de la classe (premier, dernier, moyenne de la classe) ;
 *  - discipline : heures d'absence justifiées et non justifiées, retards (une séance validée = une heure) et sanctions de conduite
 *    APPLIQUÉES (Discipline) ; travail : distinctions et sanctions du barème `work_proposals` des règles, cochées selon la ligne du
 *    conseil (distinction refusée pour conduite comprise) ; décision du conseil (bulletin annuel) et observations.
 *  - en-tête : paramètre `secondary.report_card.letterhead` (délégations FR/EN, contacts, mention de bas de page, lieu).
 * Professeur principal (Setup, ClassProfileInfo) et redoublement (Enrolment, RepeaterRoster) lus par leurs contrats (C-117) ;
 * une donnée absente (professeur principal non désigné, notes pratiques d'atelier) reste vide.
 */
final class ReportCardBuilder
{
    public const GROUPS = 'secondary.report_card.subject_groups';
    public const LETTERHEAD = 'secondary.report_card.letterhead';
    private const TRIMESTER_KINDS = ['trimester', 'semester'];

    public function __construct(
        private readonly CouncilDirectory $councils,
        private readonly ClassProfiles $classes,
        private readonly CoefficientBook $coefficients,
        private readonly PeriodTree $periods,
        private readonly RuleCalculator $calculator,
        private readonly SettingsReader $settings,
        private readonly PersonDirectory $people,
        private readonly EnrollmentDirectory $enrolments,
        private readonly BirthInfo $births,
        private readonly AttendanceTotals $attendance,
        private readonly ConductRecord $conduct,
        private readonly TeachingAssignments $assignments,
        private readonly RepeaterRoster $repeaters,
    ) {
    }

    /**
     * Variables du bulletin d'un élève pour un conseil, vide si le conseil n'est pas celui d'une classe du secondaire.
     *
     * @return array<string, scalar|null>
     */
    public function build(string $tenantId, string $establishmentId, string $councilId, string $personId, ?string $locale = null): array
    {
        $council = $this->councils->find($tenantId, $establishmentId, $councilId);
        if ($council === null) {
            return [];
        }
        $class = $this->classes->find($tenantId, $establishmentId, $council['structure_id']);
        $period = $this->periods->find($tenantId, $establishmentId, $council['period_id']);
        if ($class === null || $period === null) {
            return [];
        }
        $person = $this->people->find($tenantId, $personId);
        $en = ($locale ?? ($person === null ? null : $person->locale) ?? 'fr') === 'en';
        $rules = $this->calculator->rulesFor($tenantId, $establishmentId, $period->academicYearId, $class->structureId);
        $year = $this->periods->ofYear($tenantId, $establishmentId, $period->academicYearId);
        $annual = !in_array($period->kind, self::TRIMESTER_KINDS, true) && $period->kind !== 'sequence';
        $columns = self::columns($year, $period);
        $yearPeriod = $annual ? $period : null;

        $results = [];
        foreach ($columns as $column) {
            if ($column['included']) {
                $results[$column['id']] = $this->councils->storedResults($tenantId, $establishmentId, $class->structureId, $column['id']);
            }
        }
        $current = $this->councils->storedResults($tenantId, $establishmentId, $class->structureId, $period->id);
        $results[$period->id] = $current;
        $subjects = $this->subjects($tenantId, $establishmentId, $class, $current);
        $groups = $this->groups($tenantId, $establishmentId, $subjects, $en);
        $line = null;
        foreach ($council['lines'] as $candidate) {
            if ($candidate['person_id'] === $personId) {
                $line = $candidate;
            }
        }
        $from = $annual ? substr($year[0]->startsOn ?? $period->startsOn, 0, 10) : substr($period->startsOn, 0, 10);
        $to = substr($period->endsOn, 0, 10);
        $letterhead = $this->setting($tenantId, $establishmentId, self::LETTERHEAD);
        [$lastName, $firstNames] = $person === null ? ['', ''] : [mb_strtoupper($person->lastName), $person->firstName];
        $birth = $this->births->birthDate($tenantId, $personId);

        return [
            'secondary_authority_fr' => self::text($letterhead['authority_fr'] ?? null),
            'secondary_authority_en' => self::text($letterhead['authority_en'] ?? null),
            'secondary_school_contacts' => self::text($letterhead['contacts'] ?? null),
            'secondary_footer_note' => self::text($letterhead[$en ? 'footer_note_en' : 'footer_note'] ?? ($letterhead['footer_note'] ?? null)),
            'secondary_issue_place' => self::text($letterhead['issue_place'] ?? null),
            'secondary_academic_year' => (string) ($period->academicYearLabel ?? ''),
            'secondary_class_name' => $class->label,
            'secondary_last_name' => $lastName,
            'secondary_first_names' => $firstNames,
            'secondary_birth_line' => $birth === null ? '' : implode('/', array_reverse(explode('-', $birth))),
            'secondary_matricule' => $this->matricule($tenantId, $personId, $class),
            'secondary_parent_address' => $this->guardianContact($tenantId, $personId),
            'secondary_repeater' => $this->repeaters->repeatersAmong($tenantId, $establishmentId, [$personId]) === [] ? ($en ? 'No' : 'Non') : ($en ? 'Yes' : 'Oui'),
            'secondary_head_teacher' => $this->headTeacher($tenantId, $class),
            'secondary_subjects_table' => self::json($this->subjectsTable($rules, $columns, $groups, $results, $period, $yearPeriod, $personId, $en)),
            'secondary_summary_table' => self::json($this->summaryTable($columns, $subjects, $results, $personId, $en)),
            'secondary_class_profile_table' => self::json($this->profileTable($columns, $results, $yearPeriod, $rules, $en)),
            'secondary_discipline_table' => self::json($this->disciplineTable($tenantId, $establishmentId, $personId, $from, $to, $en)),
            'secondary_work_table' => self::json($this->workTable($rules, $line, $current[$personId]['average'] ?? null, $en)),
            'secondary_council_decision' => $annual ? (string) ($line['decision'] ?? '') : '',
            'secondary_observations' => (string) ($line['reason'] ?? ''),
        ];
    }

    /** Professeur principal désigné sur la classe (Setup), « Prénom NOM », vide s'il n'est pas désigné. */
    private function headTeacher(string $tenantId, ClassProfileInfo $class): string
    {
        $head = $class->headTeacherPersonId === null ? null : $this->people->find($tenantId, $class->headTeacherPersonId);

        return $head === null ? '' : trim($head->firstName . ' ' . mb_strtoupper($head->lastName));
    }

    /**
     * Colonnes de l'année : pour chaque trimestre (ou semestre), ses séquences puis lui-même. Une colonne est remplie si la période a
     * commencé au plus tard à la fin de la période du bulletin.
     *
     * @param list<PeriodInfo> $year
     * @return list<array{id: string, label: string, kind: string, included: bool}>
     */
    private static function columns(array $year, PeriodInfo $period): array
    {
        $trimesters = array_values(array_filter($year, static fn (PeriodInfo $p): bool => in_array($p->kind, self::TRIMESTER_KINDS, true)));
        usort($trimesters, static fn (PeriodInfo $a, PeriodInfo $b): int => [$a->ordinal, $a->startsOn] <=> [$b->ordinal, $b->startsOn]);
        $end = substr($period->endsOn, 0, 10);
        $columns = [];
        $sequenceNo = 0;
        foreach ($trimesters as $trimester) {
            $sequences = array_values(array_filter($year, static fn (PeriodInfo $p): bool => $p->kind === 'sequence' && $p->parentId === $trimester->id));
            usort($sequences, static fn (PeriodInfo $a, PeriodInfo $b): int => [$a->ordinal, $a->startsOn] <=> [$b->ordinal, $b->startsOn]);
            foreach ($sequences as $sequence) {
                $columns[] = ['id' => $sequence->id, 'label' => 'SEQ' . ++$sequenceNo, 'kind' => 'sequence', 'included' => substr($sequence->startsOn, 0, 10) <= $end];
            }
            $columns[] = ['id' => $trimester->id, 'label' => 'TRIM' . $trimester->ordinal, 'kind' => $trimester->kind, 'included' => substr($trimester->startsOn, 0, 10) <= $end];
        }

        return $columns;
    }

    /**
     * Matières du bulletin : coefficients versionnés de Setup du niveau et de la série, sinon matières des résultats de la période.
     *
     * @param array<string, array<string, mixed>> $current
     * @return list<array{subject_id: string, code: string, label: string, factor: float, counts: bool, teacher: string}>
     */
    private function subjects(string $tenantId, string $establishmentId, ClassProfileInfo $class, array $current): array
    {
        $teachers = [];
        foreach ($this->assignments->forClass($tenantId, $establishmentId, $class->structureId, $class->academicYearId) as $assignment) {
            if (!isset($teachers[$assignment->subjectId])) {
                $teacher = $this->people->find($tenantId, $assignment->teacherPersonId);
                $teachers[$assignment->subjectId] = $teacher === null ? '' : $teacher->fullName();
            }
        }
        $subjects = [];
        foreach ($this->coefficients->forLevel($tenantId, $establishmentId, $class->academicYearId, $class->levelCode, $class->streamId) as $line) {
            $subjects[] = ['subject_id' => $line->subjectId, 'code' => $line->code, 'label' => $line->label, 'factor' => $line->coefficient,
                'counts' => $line->countsInAverage, 'teacher' => $teachers[$line->subjectId] ?? ''];
        }
        if ($subjects !== []) {
            return $subjects;
        }
        $seen = [];
        foreach ($current as $result) {
            foreach ($result['details'] as $detail) {
                $id = (string) ($detail['subject_id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $subjects[] = ['subject_id' => $id, 'code' => (string) ($detail['code'] ?? $id), 'label' => (string) ($detail['name'] ?? $detail['code'] ?? $id),
                    'factor' => is_numeric($detail['coefficient'] ?? null) ? (float) $detail['coefficient'] : 0.0, 'counts' => true, 'teacher' => $teachers[$id] ?? ''];
            }
        }

        return $subjects;
    }

    /**
     * Groupes du bulletin (paramètre : liste de {label, label_en, subjects: [codes]}) ; matières restantes dans un dernier groupe.
     *
     * @param list<array{subject_id: string, code: string, label: string, factor: float, counts: bool, teacher: string}> $subjects
     * @return list<array{label: string, subjects: list<array{subject_id: string, code: string, label: string, factor: float, counts: bool, teacher: string}>}>
     */
    private function groups(string $tenantId, string $establishmentId, array $subjects, bool $en): array
    {
        $setting = $this->settings->value($tenantId, $establishmentId, self::GROUPS, null);
        $groups = [];
        $placed = [];
        foreach (is_array($setting) && array_is_list($setting) ? $setting : [] as $group) {
            if (!is_array($group) || !is_array($group['subjects'] ?? null)) {
                continue;
            }
            $codes = array_map('strval', $group['subjects']);
            $members = array_values(array_filter($subjects, static fn (array $s): bool => in_array($s['code'], $codes, true)));
            foreach ($members as $member) {
                $placed[$member['subject_id']] = true;
            }
            $label = self::text($en ? ($group['label_en'] ?? $group['label'] ?? null) : ($group['label'] ?? null));
            if ($members !== []) {
                $groups[] = ['label' => $label, 'subjects' => $members];
            }
        }
        $rest = array_values(array_filter($subjects, static fn (array $s): bool => !isset($placed[$s['subject_id']])));
        if ($rest !== []) {
            $groups[] = ['label' => $groups === [] ? ($en ? 'Subjects' : 'Matières') : ($en ? 'Other subjects' : 'Autres matières'), 'subjects' => $rest];
        }

        return $groups;
    }

    /**
     * @param list<array{id: string, label: string, kind: string, included: bool}> $columns
     * @param list<array{label: string, subjects: list<array{subject_id: string, code: string, label: string, factor: float, counts: bool, teacher: string}>}> $groups
     * @param array<string, array<string, array<string, mixed>>> $results
     * @return array<string, mixed>
     */
    private function subjectsTable(CalculationRules $rules, array $columns, array $groups, array $results, PeriodInfo $period, ?PeriodInfo $yearPeriod, string $personId, bool $en): array
    {
        $span = count($columns) + 5;
        $rows = [];
        $groupNo = 0;
        foreach ($groups as $group) {
            ++$groupNo;
            $rows[] = ['kind' => 'group', 'cells' => [['text' => $group['label'], 'colspan' => $span]]];
            foreach ($group['subjects'] as $subject) {
                $cells = [$subject['label'] . ($subject['teacher'] === '' ? '' : "\n" . $subject['teacher']), self::number($subject['factor'], $en)];
                foreach ($columns as $column) {
                    $cells[] = $column['included'] ? self::number(self::subjectAverage($results[$column['id']][$personId] ?? null, $subject['subject_id']), $en) : '';
                }
                $annual = $yearPeriod === null ? null : self::subjectAverage($results[$yearPeriod->id][$personId] ?? null, $subject['subject_id']);
                $own = self::subjectAverage($results[$period->id][$personId] ?? null, $subject['subject_id']);
                $peers = [];
                foreach ($results[$period->id] ?? [] as $peer => $result) {
                    $peers[(string) $peer] = self::subjectAverage($result, $subject['subject_id']);
                }
                $rank = $own === null ? null : ($this->calculator->ranks($peers)[$personId] ?? null);
                $cells[] = $yearPeriod === null ? '' : self::number($annual, $en);
                $cells[] = self::rank($rank, $en);
                $cells[] = $own === null ? '' : ($rules->mention($own) ?? '');
                $rows[] = $cells;
            }
            $total = ['TOTAL ' . $groupNo, self::number(array_sum(array_column($group['subjects'], 'factor')), $en)];
            foreach ($columns as $column) {
                $total[] = $column['included'] ? self::number(self::groupAverage($results[$column['id']][$personId] ?? null, $group['subjects'], $rules), $en) : '';
            }
            $total[] = $yearPeriod === null ? '' : self::number(self::groupAverage($results[$yearPeriod->id][$personId] ?? null, $group['subjects'], $rules), $en);
            $peers = [];
            foreach ($results[$period->id] ?? [] as $peer => $result) {
                $peers[(string) $peer] = self::groupAverage($result, $group['subjects'], $rules);
            }
            $total[] = isset($peers[$personId]) ? self::rank($this->calculator->ranks($peers)[$personId] ?? null, $en) : '';
            $total[] = '';
            $rows[] = ['kind' => 'total', 'cells' => $total];
        }
        $centered = ['label' => '', 'align' => 'center'];
        $head = [[
            ['text' => $en ? 'Subjects and teachers' : 'Matières et noms des professeurs', 'rowspan' => 2], ['text' => 'Coef', 'rowspan' => 2],
        ], []];
        $byTrimester = [];
        foreach ($columns as $column) {
            $byTrimester[] = $column;
            if ($column['kind'] !== 'sequence') {
                $head[0][] = ['text' => ($en ? 'TERM ' : 'TRIMESTRE ') . substr($column['label'], strlen('TRIM')), 'colspan' => count($byTrimester)];
                foreach ($byTrimester as $member) {
                    $head[1][] = $member['label'];
                }
                $byTrimester = [];
            }
        }
        $head[0][] = ['text' => $en ? "Annual\naverage" : "Moyenne\nannuelle", 'rowspan' => 2];
        $head[0][] = ['text' => $en ? 'Rank' : 'Rang', 'rowspan' => 2];
        $head[0][] = ['text' => $en ? 'Remarks and signatures' : 'Appréciations et signatures', 'rowspan' => 2];

        return [
            'class' => 'compact',
            'columns' => [['label' => '', 'width' => 24, 'align' => 'left'], $centered, ...array_fill(0, count($columns), $centered), $centered, $centered, ['label' => '', 'width' => 13, 'align' => 'left']],
            'head' => $head,
            'rows' => $rows,
        ];
    }

    /**
     * @param list<array{id: string, label: string, kind: string, included: bool}> $columns
     * @param list<array{subject_id: string, code: string, label: string, factor: float, counts: bool, teacher: string}> $subjects
     * @param array<string, array<string, array<string, mixed>>> $results
     * @return array<string, mixed>
     */
    private function summaryTable(array $columns, array $subjects, array $results, string $personId, bool $en): array
    {
        $points = [$en ? 'Total' : 'Total'];
        $averages = [$en ? 'Average' : 'Moy / Aver'];
        $ranks = [$en ? 'Rank' : 'Rang / Rank'];
        foreach ($columns as $column) {
            $result = $column['included'] ? ($results[$column['id']][$personId] ?? null) : null;
            $sum = null;
            foreach ($subjects as $subject) {
                $average = self::subjectAverage($result, $subject['subject_id']);
                if ($average !== null && $subject['counts']) {
                    $sum = ($sum ?? 0.0) + $average * $subject['factor'];
                }
            }
            $points[] = self::number($sum, $en);
            $averages[] = self::number(is_numeric($result['average'] ?? null) ? (float) $result['average'] : null, $en);
            $ranks[] = self::rank(is_int($result['rank'] ?? null) ? $result['rank'] : null, $en);
        }

        return [
            'class' => 'compact',
            'columns' => [['label' => '', 'align' => 'left'], ...array_map(static fn (array $c): array => ['label' => $c['label'], 'align' => 'center'], $columns)],
            'rows' => [$points, $averages, $ranks],
        ];
    }

    /**
     * @param list<array{id: string, label: string, kind: string, included: bool}> $columns
     * @param array<string, array<string, array<string, mixed>>> $results
     * @return array<string, mixed>
     */
    private function profileTable(array $columns, array $results, ?PeriodInfo $yearPeriod, CalculationRules $rules, bool $en): array
    {
        $periods = array_values(array_filter($columns, static fn (array $c): bool => $c['kind'] !== 'sequence'));
        if ($yearPeriod !== null) {
            $periods[] = ['id' => $yearPeriod->id, 'label' => $en ? 'Year' : 'Année', 'kind' => $yearPeriod->kind, 'included' => true];
        }
        $high = [$en ? 'Highest average' : 'Moyenne du 1er'];
        $low = [$en ? 'Lowest average' : 'Moyenne du dernier'];
        $mean = [$en ? 'Class average' : 'Moyenne générale'];
        foreach ($periods as $column) {
            $values = [];
            foreach ($column['included'] ? ($results[$column['id']] ?? []) : [] as $result) {
                if (is_numeric($result['average'] ?? null)) {
                    $values[] = (float) $result['average'];
                }
            }
            $high[] = $values === [] ? '' : self::number(max($values), $en);
            $low[] = $values === [] ? '' : self::number(min($values), $en);
            $mean[] = $values === [] ? '' : self::number($rules->round(array_sum($values) / count($values)), $en);
        }

        return [
            'class' => 'compact',
            'columns' => [['label' => '', 'align' => 'left'], ...array_map(static fn (array $c): array => ['label' => $c['label'], 'align' => 'center'], $periods)],
            'rows' => [$high, $low, $mean],
        ];
    }

    /** @return array<string, mixed> */
    private function disciplineTable(string $tenantId, string $establishmentId, string $personId, string $from, string $to, bool $en): array
    {
        $summary = $this->attendance->byLearner($tenantId, $establishmentId, $from, $to)[$personId] ?? null;
        $absences = $summary === null ? 0 : $summary->absences;
        $unjustified = $summary === null ? 0 : $summary->unjustifiedAbsences;
        $rows = [
            [$en ? 'Excused absences (h)' : 'Absences justifiées (h)', self::hours(max(0, $absences - $unjustified))],
            [$en ? 'Unexcused absences (h)' : 'Absences non justifiées (h)', self::hours($unjustified)],
            [$en ? 'Late arrivals' : 'Retards', (string) ($summary === null ? 0 : $summary->lates)],
        ];
        $sanctions = $this->conduct->appliedFor($tenantId, $establishmentId, $personId, $from, $to);
        foreach ($sanctions as $sanction) {
            $rows[] = [$sanction['label'], $en ? 'Yes' : 'Oui'];
        }
        if ($sanctions === []) {
            $rows[] = [$en ? 'Conduct sanctions' : 'Sanctions de conduite', $en ? 'None' : 'Aucune'];
        }

        return ['class' => 'compact', 'columns' => [['label' => '', 'align' => 'left', 'width' => 75], ['label' => '', 'align' => 'center']], 'rows' => $rows];
    }

    /**
     * Distinctions et sanctions du travail du barème des règles ; cochée : la proposition retenue sur la ligne du conseil (sinon calculée).
     *
     * @param array<string, mixed>|null $line
     * @return array<string, mixed>
     */
    private function workTable(CalculationRules $rules, ?array $line, mixed $average, bool $en): array
    {
        $extras = is_array($line['extras'] ?? null) ? $line['extras'] : [];
        if (array_key_exists('work_proposal', $extras)) {
            $chosen = is_array($extras['work_proposal']) ? (string) ($extras['work_proposal']['code'] ?? '') : '';
        } else {
            $proposal = WorkProposals::forAverage($rules->options, is_numeric($average) ? (float) $average : null);
            $chosen = $proposal === null ? '' : $proposal['code'];
        }
        $rows = [];
        foreach ((array) ($rules->options[WorkProposals::OPTION] ?? []) as $tier) {
            if (is_array($tier) && isset($tier['code'])) {
                $rows[] = [(string) ($tier['label'] ?? $tier['code']), (string) $tier['code'] === $chosen ? ($en ? 'Yes' : 'Oui') : ($en ? 'No' : 'Non')];
            }
        }
        if (($extras['distinction_refused'] ?? false) === true) {
            $rows[] = [$en ? 'Distinction withheld (conduct)' : 'Distinction refusée (conduite)', $en ? 'Yes' : 'Oui'];
        }

        return ['class' => 'compact', 'columns' => [['label' => '', 'align' => 'left', 'width' => 75], ['label' => '', 'align' => 'center']], 'rows' => $rows];
    }

    /** @param array<string, mixed>|null $result */
    private static function subjectAverage(?array $result, string $subjectId): ?float
    {
        foreach ((array) ($result['details'] ?? []) as $detail) {
            if (is_array($detail) && (string) ($detail['subject_id'] ?? '') === $subjectId) {
                return is_numeric($detail['average'] ?? null) ? (float) $detail['average'] : null;
            }
        }

        return null;
    }

    /**
     * Moyenne pondérée d'un groupe : Σ (moyenne × coefficient) ÷ Σ coefficients des matières notées qui comptent dans la moyenne.
     *
     * @param array<string, mixed>|null $result
     * @param list<array{subject_id: string, code: string, label: string, factor: float, counts: bool, teacher: string}> $subjects
     */
    private static function groupAverage(?array $result, array $subjects, CalculationRules $rules): ?float
    {
        if ($result === null) {
            return null;
        }
        $sum = 0.0;
        $weights = 0.0;
        foreach ($subjects as $subject) {
            $average = self::subjectAverage($result, $subject['subject_id']);
            if ($average !== null && $subject['counts'] && $subject['factor'] > 0) {
                $sum += $average * $subject['factor'];
                $weights += $subject['factor'];
            }
        }

        return $weights > 0 ? $rules->round($sum / $weights) : null;
    }

    private function matricule(string $tenantId, string $personId, ClassProfileInfo $class): string
    {
        foreach ($this->enrolments->forPerson($tenantId, $personId) as $enrolment) {
            if ($enrolment->structureId === $class->structureId && $enrolment->internalNumber !== null) {
                return $enrolment->internalNumber;
            }
        }

        return '';
    }

    private function guardianContact(string $tenantId, string $personId): string
    {
        foreach ($this->people->guarantorsOf($tenantId, $personId) as $guarantor) {
            if ($guarantor->phone !== null && $guarantor->phone !== '') {
                return $guarantor->fullName() . ' · ' . $guarantor->phone;
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function setting(string $tenantId, string $establishmentId, string $key): array
    {
        $value = $this->settings->value($tenantId, $establishmentId, $key, null);

        return is_array($value) ? $value : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function number(?float $value, bool $en): string
    {
        return $value === null ? '' : number_format($value, 2, $en ? '.' : ',', '');
    }

    private static function hours(int $sessions): string
    {
        return rtrim(rtrim(number_format($sessions * Defaults::HOURS_PER_SESSION, 2, ',', ''), '0'), ',');
    }

    private static function rank(?int $rank, bool $en): string
    {
        if ($rank === null) {
            return '';
        }
        if (!$en) {
            return $rank . ($rank === 1 ? 'er' : 'e');
        }
        $suffix = in_array($rank % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$rank % 10] ?? 'th');

        return $rank . $suffix;
    }

    /** @param array<string, mixed> $table */
    private static function json(array $table): string
    {
        return json_encode($table, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

}
