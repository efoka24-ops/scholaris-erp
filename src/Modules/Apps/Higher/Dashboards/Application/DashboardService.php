<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Application;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicLabels;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\AdmissionStatistics;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\AssiduityStatistics;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\CollectionStatistics;
use Scholaris\Modules\Apps\Higher\Dashboards\Domain\Board;
use Scholaris\Modules\Apps\Higher\Dashboards\Domain\SmallCells;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentStatistics;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradingStatistics;
use Scholaris\Modules\Apps\Higher\NationalExams\Contracts\NationalResultStatistics;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;
use Scholaris\Modules\Apps\Higher\Vacations\Contracts\VacationStatistics;

/**
 * Calcule les tableaux de bord de l'IPES à partir des contrats d'agrégation des modules Higher (une requête GROUP BY par
 * tableau, aucune lecture par étudiant) : cartes de chiffres et tableaux. Les effectifs inférieurs à 5 sont masqués (SmallCells).
 * Forme : `{cards: list<{key, value, unit}>, tables: list<{key, columns, rows}>}`.
 */
final class DashboardService
{
    public function __construct(
        private readonly AdmissionStatistics $admissions,
        private readonly EnrolmentStatistics $enrolments,
        private readonly CollectionStatistics $collection,
        private readonly GradingStatistics $grading,
        private readonly AssiduityStatistics $assiduity,
        private readonly VacationStatistics $vacations,
        private readonly NationalResultStatistics $national,
        private readonly MinesupStatistics $minesup,
        private readonly AcademicLabels $labels,
        private readonly DashboardClock $clock,
    ) {
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    public function build(string $board, string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        return match ($board) {
            Board::ENROLMENT => $this->enrolment($tenantId, $establishmentId, $perimeter, $filter),
            Board::FINANCE => $this->finance($tenantId, $establishmentId, $perimeter, $filter),
            Board::GRADING => $this->gradingProgress($tenantId, $establishmentId, $perimeter, $filter),
            Board::RESULTS => $this->results($tenantId, $establishmentId, $perimeter, $filter),
            Board::ATTENDANCE => $this->attendance($tenantId, $establishmentId, $perimeter, $filter),
            Board::VACATIONS => $this->vacations($tenantId, $establishmentId, $perimeter, $filter),
            Board::NATIONAL => $this->nationalResults($tenantId, $establishmentId, $perimeter, $filter),
            default => $this->minesup->build($tenantId, $establishmentId, $perimeter, $filter, $this->clock->today()),
        };
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    private function enrolment(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        $funnel = $this->admissions->funnel($tenantId, $establishmentId, $perimeter, $filter);
        $headcount = $this->enrolments->headcount($tenantId, $establishmentId, $perimeter, $filter);
        $applications = array_sum(array_column($funnel, 'applications'));
        $admitted = array_sum(array_column($funnel, 'admitted'));
        $enrolled = array_sum(array_column($headcount, 'enrolled'));
        foreach ($funnel as &$row) {
            $row['admission_rate'] = self::rate($row['admitted'], $row['applications']);
        }
        unset($row);
        $funnel = SmallCells::apply($this->named($tenantId, $establishmentId, $funnel), ['applications', 'admitted', 'enrolled'], ['applications', 'admitted', 'enrolled', 'admission_rate']);
        $headcount = SmallCells::apply($this->named($tenantId, $establishmentId, $headcount), ['enrolled'], ['enrolled']);

        return [
            'cards' => [
                self::card('applications', SmallCells::card($applications)),
                self::card('admitted', SmallCells::card($admitted)),
                self::card('enrolled', SmallCells::card($enrolled)),
                self::card('admission_rate', $applications >= SmallCells::MINIMUM ? self::rate($admitted, $applications) : null, 'percent'),
            ],
            'tables' => [
                self::table('funnel', ['school', 'program', 'applications', 'admitted', 'admission_rate', 'enrolled'], $funnel),
                self::table('headcount', ['school', 'program', 'level', 'enrolled'], $headcount),
            ],
            'notes' => [],
        ];
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    private function finance(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        $rows = $this->collection->bySchoolProgram($tenantId, $establishmentId, $perimeter, $filter, $this->clock->today());
        $channels = $this->collection->byChannel($tenantId, $establishmentId, $perimeter, $filter);
        $accounts = array_sum(array_column($rows, 'accounts'));
        $expected = array_sum(array_column($rows, 'expected_xaf'));
        $collected = array_sum(array_column($rows, 'collected_xaf'));
        $outstanding = array_sum(array_column($rows, 'outstanding_xaf'));
        $overdue = array_sum(array_column($rows, 'overdue_xaf'));
        $shown = $accounts === 0 || $accounts >= SmallCells::MINIMUM;
        foreach ($rows as &$row) {
            $row['recovery_rate'] = self::rate($row['collected_xaf'], $row['expected_xaf']);
        }
        unset($row);
        $money = ['accounts', 'expected_xaf', 'collected_xaf', 'outstanding_xaf', 'overdue_xaf', 'overdue_accounts', 'recovery_rate'];
        $rows = SmallCells::apply($this->named($tenantId, $establishmentId, $rows), ['accounts'], $money);
        $channels = SmallCells::apply($channels, ['payments'], ['payments', 'amount_xaf']);

        return [
            'cards' => [
                self::card('expected_xaf', $shown ? $expected : null, 'xaf'),
                self::card('collected_xaf', $shown ? $collected : null, 'xaf'),
                self::card('outstanding_xaf', $shown ? $outstanding : null, 'xaf'),
                self::card('overdue_xaf', $shown ? $overdue : null, 'xaf'),
                self::card('recovery_rate', $shown ? self::rate($collected, $expected) : null, 'percent'),
            ],
            'tables' => [
                self::table('collection', ['school', 'program', 'accounts', 'expected_xaf', 'collected_xaf', 'outstanding_xaf', 'overdue_xaf', 'overdue_accounts', 'recovery_rate'], $rows),
                self::table('channels', ['channel', 'payments', 'amount_xaf'], $channels),
            ],
            'notes' => ['Impayés : tranches échues à la date du jour et non soldées.'],
        ];
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    private function gradingProgress(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        $rows = $this->grading->progress($tenantId, $establishmentId, $perimeter, $filter, $this->clock->today());
        $assessments = array_sum(array_column($rows, 'assessments'));
        $expected = array_sum(array_column($rows, 'expected'));
        $entered = array_sum(array_column($rows, 'entered'));
        $overdue = array_sum(array_column($rows, 'overdue'));
        $published = array_sum(array_column($rows, 'published'));
        foreach ($rows as &$row) {
            $row['entry_rate'] = self::rate($row['entered'], $row['expected']);
        }
        unset($row);

        return [
            'cards' => [
                self::card('assessments', $assessments),
                self::card('entry_rate', self::rate($entered, $expected), 'percent'),
                self::card('overdue', $overdue),
                self::card('published', $published),
            ],
            'tables' => [self::table('progress', ['school', 'program', 'element_code', 'element_title', 'assessments', 'draft', 'submitted', 'validated', 'locked', 'published', 'overdue', 'entry_rate'], $this->named($tenantId, $establishmentId, $rows))],
            'notes' => ['Avancement : cellules saisies (note ou absence) sur cellules attendues, par EC.'],
        ];
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    private function results(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        $normal = $this->grading->success($tenantId, $establishmentId, $perimeter, $filter, 'normal');
        $retake = $this->grading->success($tenantId, $establishmentId, $perimeter, $filter, 'retake');
        $students = array_sum(array_column($normal, 'students'));
        $passed = array_sum(array_column($normal, 'passed'));
        $retakeStudents = array_sum(array_column($retake, 'students'));
        $retakePassed = array_sum(array_column($retake, 'passed'));

        $tables = [
            self::table('success_element', ['school', 'program', 'element_code', 'element_title', 'students', 'passed', 'success_rate'], $this->successRows($tenantId, $establishmentId, $normal)),
            self::table('success_unit', ['school', 'program', 'unit', 'elements', 'students', 'passed', 'success_rate'], $this->successRows($tenantId, $establishmentId, $this->byUnit($normal))),
            self::table('success_program', ['school', 'program', 'level', 'students', 'passed', 'success_rate'], $this->successRows($tenantId, $establishmentId, $this->byProgram($normal))),
            self::table('retake', ['school', 'program', 'element_code', 'element_title', 'students', 'passed', 'success_rate'], $this->successRows($tenantId, $establishmentId, $retake)),
        ];

        return [
            'cards' => [
                self::card('students', SmallCells::card($students)),
                self::card('success_rate', $students >= SmallCells::MINIMUM ? self::rate($passed, $students) : null, 'percent'),
                self::card('retake_students', SmallCells::card($retakeStudents)),
                self::card('retake_success_rate', $retakeStudents >= SmallCells::MINIMUM ? self::rate($retakePassed, $retakeStudents) : null, 'percent'),
            ],
            'tables' => $tables,
            'notes' => ['Réussite : moyenne pondérée de l\'EC à 10/20 au moins, sur les évaluations verrouillées ou publiées ; les effectifs sont des couples étudiant-EC.'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function successRows(string $tenantId, string $establishmentId, array $rows): array
    {
        foreach ($rows as &$row) {
            $row['success_rate'] = self::rate((int) $row['passed'], (int) $row['students']);
        }
        unset($row);

        return SmallCells::apply($this->named($tenantId, $establishmentId, $rows), ['students'], ['students', 'passed', 'success_rate']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function byUnit(array $rows): array
    {
        $units = [];
        foreach ($rows as $row) {
            $key = $row['school_id'] . '|' . $row['program_id'] . '|' . $row['unit_id'];
            $units[$key] ??= ['school_id' => $row['school_id'], 'program_id' => $row['program_id'], 'unit' => $row['unit_id'], 'codes' => [], 'students' => 0, 'passed' => 0];
            $units[$key]['codes'][] = $row['element_code'];
            $units[$key]['students'] += $row['students'];
            $units[$key]['passed'] += $row['passed'];
        }

        return array_values(array_map(static function (array $unit): array {
            $codes = $unit['codes'];
            unset($unit['codes']);

            return $unit + ['elements' => implode(', ', $codes)];
        }, $units));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function byProgram(array $rows): array
    {
        $programs = [];
        foreach ($rows as $row) {
            $key = $row['school_id'] . '|' . $row['program_id'] . '|' . $row['level_id'];
            $programs[$key] ??= ['school_id' => $row['school_id'], 'program_id' => $row['program_id'], 'level_id' => $row['level_id'], 'students' => 0, 'passed' => 0];
            $programs[$key]['students'] += $row['students'];
            $programs[$key]['passed'] += $row['passed'];
        }

        return array_values($programs);
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    private function attendance(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        $rows = $this->assiduity->byGroupElement($tenantId, $establishmentId, $perimeter, $filter);
        $sessions = array_sum(array_column($rows, 'sessions'));
        $records = array_sum(array_column($rows, 'records'));
        $absent = array_sum(array_column($rows, 'absent'));
        $unjustified = array_sum(array_column($rows, 'unjustified'));
        foreach ($rows as &$row) {
            $row['absence_rate'] = self::rate($row['absent'], $row['records']);
            $row['unjustified_rate'] = self::rate($row['unjustified'], $row['records']);
        }
        unset($row);
        $hidden = ['records', 'present', 'late', 'absent', 'unjustified', 'absence_rate', 'unjustified_rate'];

        return [
            'cards' => [
                self::card('sessions', $sessions),
                self::card('absence_rate', $records >= SmallCells::MINIMUM || $records === 0 ? self::rate($absent, $records) : null, 'percent'),
                self::card('unjustified_rate', $records >= SmallCells::MINIMUM || $records === 0 ? self::rate($unjustified, $records) : null, 'percent'),
            ],
            'tables' => [self::table('assiduity', ['group', 'label', 'sessions', 'records', 'present', 'late', 'absent', 'absence_rate', 'unjustified_rate'], SmallCells::apply($this->named($tenantId, $establishmentId, $rows), ['records'], $hidden))],
            'notes' => ['Assiduité calculée sur les appels validés des séances rattachées à un EC.'],
        ];
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    private function vacations(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        $rows = $this->vacations->byDepartmentMonth($tenantId, $establishmentId, $perimeter, $filter);
        $hours = array_sum(array_column($rows, 'minutes')) / 60;
        $hetd = array_sum(array_column($rows, 'hetd_milli')) / 1000;
        $net = array_sum(array_column($rows, 'net_xaf'));
        $paid = array_sum(array_column($rows, 'paid_xaf'));
        foreach ($rows as &$row) {
            $row['hours'] = round($row['minutes'] / 60, 2);
            $row['hetd'] = round($row['hetd_milli'] / 1000, 2);
        }
        unset($row);

        return [
            'cards' => [
                self::card('hours', round($hours, 2), 'hours'),
                self::card('hetd', round($hetd, 2), 'hours'),
                self::card('net_xaf', $net, 'xaf'),
                self::card('paid_xaf', $paid, 'xaf'),
            ],
            'tables' => [self::table('vacations', ['school', 'department', 'month', 'statements', 'hours', 'hetd', 'gross_xaf', 'net_xaf', 'paid_xaf'], $this->named($tenantId, $establishmentId, $rows))],
            'notes' => ['Relevés validés par l\'école, approuvés ou payés ; HETD = heures équivalent travaux dirigés.'],
        ];
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    private function nationalResults(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        $rows = $this->national->results($tenantId, $establishmentId, $perimeter, $filter);
        $candidates = array_sum(array_column($rows, 'candidates'));
        $resulted = array_sum(array_column($rows, 'resulted'));
        $passed = array_sum(array_column($rows, 'passed'));
        foreach ($rows as &$row) {
            $row['pass_rate'] = self::rate($row['passed'], $row['resulted']);
        }
        unset($row);
        $rows = SmallCells::apply($this->named($tenantId, $establishmentId, $rows), ['candidates', 'resulted'], ['candidates', 'resulted', 'passed', 'failed', 'absent', 'pass_rate']);

        return [
            'cards' => [
                self::card('candidates', SmallCells::card($candidates)),
                self::card('resulted', SmallCells::card($resulted)),
                self::card('pass_rate', $resulted >= SmallCells::MINIMUM ? self::rate($passed, $resulted) : null, 'percent'),
            ],
            'tables' => [self::table('national', ['exam_type', 'academic_year_id', 'school', 'program', 'candidates', 'resulted', 'passed', 'failed', 'absent', 'pass_rate'], $rows)],
            'notes' => ['Examens nationaux BTS et HND : candidats non retirés, résultats importés.'],
        ];
    }

    /**
     * Ajoute le libellé de chaque identifiant d'unité académique (une lecture par type, jamais par ligne).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function named(string $tenantId, string $establishmentId, array $rows): array
    {
        $map = ['school_id' => ['school', AcademicLabels::SCHOOL], 'department_id' => ['department', AcademicLabels::DEPARTMENT],
            'program_id' => ['program', AcademicLabels::PROGRAM], 'level_id' => ['level', AcademicLabels::LEVEL], 'group_id' => ['group', AcademicLabels::GROUP]];
        $names = [];
        foreach ($map as $idKey => [, $kind]) {
            $ids = [];
            foreach ($rows as $row) {
                if (isset($row[$idKey])) {
                    $ids[] = (string) $row[$idKey];
                }
            }
            $names[$idKey] = $ids === [] ? [] : $this->labels->names($tenantId, $establishmentId, $kind, $ids);
        }

        return array_map(static function (array $row) use ($map, $names): array {
            foreach ($map as $idKey => [$label]) {
                if (isset($row[$idKey])) {
                    $row[$label] = $names[$idKey][(string) $row[$idKey]] ?? (string) $row[$idKey];
                }
            }

            return $row;
        }, $rows);
    }

    public static function rate(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    /** @return array<string, mixed> */
    private static function card(string $key, int|float|null $value, string $unit = 'count'): array
    {
        return ['key' => $key, 'value' => $value, 'unit' => $unit];
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function table(string $key, array $columns, array $rows): array
    {
        return ['key' => $key, 'columns' => $columns, 'rows' => $rows];
    }
}
