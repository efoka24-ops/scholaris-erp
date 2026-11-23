<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Dashboards\Domain\DashboardExportTypes;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\RepeaterRoster;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamOutcomes;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;
use Scholaris\Modules\Socle\Integrations\Contracts\ExportFile;
use Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;

/**
 * Rapports exportables (CSV ou XLSX, fichier joint), formats libres à confirmer (Q12) :
 *  - `objectives` : objectifs O1 à O7 (droits du tableau de bord du chef ; O4 et O5 avec un droit des finances) ;
 *  - DOC-32 rapport de rentrée : `rentree-classes` (effectifs par classe, sexe, redoublants), `rentree-census` (niveau, sexe, âge),
 *    `rentree-staff` (personnel par statut) ;
 *  - DOC-34 rapport annuel : `annual-levels` (résultats par niveau, comparaison avec `previous_year_id`), `annual-exams` (examens) ;
 *  - `network` : vue réseau masquée (droit `secondary.network.read`).
 * Les rapports d'établissement exigent `secondary.dashboard.read` sur tout l'établissement.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly Access $access,
        private readonly DashboardService $dashboards,
        private readonly NetworkDashboard $network,
        private readonly DashboardSettings $settings,
        private readonly PersonDemographics $demographics,
        private readonly RepeaterRoster $repeaters,
        private readonly EstablishmentStaffRoster $staff,
        private readonly CouncilDirectory $councils,
        private readonly ExamOutcomes $exams,
        private readonly TabularExporter $exporter,
    ) {
    }

    /** @param array<string, mixed> $query */
    public function export(Principal $principal, string $establishmentId, string $report, array $query): ExportFile
    {
        if (!isset(DashboardExportTypes::REPORTS[$report])) {
            throw HttpException::notFound('Rapport inconnu.');
        }
        $format = $query['format'] ?? 'csv';
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw HttpException::validation(['format' => ['Format attendu : csv ou xlsx.']]);
        }
        $records = $report === 'network' ? $this->networkRows($principal, $establishmentId, $query) : $this->establishmentRows($principal, $establishmentId, $report, $query);
        $file = $this->exporter->render($principal->tenantId, $establishmentId, DashboardExportTypes::REPORTS[$report]['key'], $records, $format);
        $this->access->audit($principal, 'secondary.dashboard.report_exported', 'secondary_dashboard_report', $report, null, ['format' => $format, 'rows' => $file->rows]);

        return $file;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, scalar|null>>
     */
    private function networkRows(Principal $principal, string $establishmentId, array $query): array
    {
        $rows = [];
        foreach ($this->network->build($principal, $establishmentId, $query)['establishments'] as $row) {
            $rows[] = ['masked' => implode(', ', $row['masked'])] + array_intersect_key($row, array_flip(['establishment', 'academic_year_id', 'students', 'female', 'male', 'exam_pass_rate', 'recovery_rate']));
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, scalar|null>>
     */
    private function establishmentRows(Principal $principal, string $establishmentId, string $report, array $query): array
    {
        if ($report === 'objectives') {
            $head = $this->dashboards->head($principal, $establishmentId, $query);
            $term = $head['period']['term']['id'] ?? null;

            return array_values(array_map(static fn (array $o): array => [
                'code' => $o['code'], 'label' => $o['label'], 'available' => $o['available'] ? 'oui' : 'non', 'value' => $o['value'], 'unit' => $o['unit'],
                'target' => $o['target'], 'direction' => $o['direction'], 'met' => $o['met'] === null ? null : ($o['met'] ? 'oui' : 'non'),
                'reason' => $o['reason'], 'academic_year_id' => $head['period']['academic_year_id'], 'term_id' => $term,
            ], $head['objectives']));
        }
        $perimeter = $this->access->require($principal, $establishmentId, DashboardService::READ);
        $this->access->assertWhole($principal, $perimeter, 'secondary_dashboard_report');
        [, $period, $roster] = $this->dashboards->frame($principal->tenantId, $establishmentId, $query);

        return match ($report) {
            'rentree-classes' => $this->rentreeClasses($principal->tenantId, $establishmentId, $roster),
            'rentree-census' => $this->census($principal->tenantId, $establishmentId, $period, $roster),
            'rentree-staff' => $this->staffRows($principal->tenantId, $establishmentId),
            'annual-levels' => $this->annualLevels($principal->tenantId, $establishmentId, $period, $roster, $query),
            default => $this->annualExams($principal->tenantId, $establishmentId, $period, $query),
        };
    }

    /** @return list<array<string, scalar|null>> */
    private function rentreeClasses(string $tenantId, string $establishmentId, Roster $roster): array
    {
        $persons = $roster->persons();
        $genders = $persons === [] ? [] : $this->demographics->ofPersons($tenantId, $persons);
        $repeaters = array_flip($persons === [] ? [] : $this->repeaters->repeatersAmong($tenantId, $establishmentId, $persons));
        $rows = [];
        foreach ($roster->classes as $class) {
            $female = 0;
            $male = 0;
            $repeating = 0;
            foreach ($class['persons'] as $person) {
                $female += ($genders[$person]['gender'] ?? null) === 'female' ? 1 : 0;
                $male += ($genders[$person]['gender'] ?? null) === 'male' ? 1 : 0;
                $repeating += isset($repeaters[$person]) ? 1 : 0;
            }
            $p = $class['profile'];
            $rows[] = [
                'level' => $p->levelCode, 'class' => $p->label, 'section' => $p->sectionCode, 'teaching' => $p->teachingKind, 'female' => $female, 'male' => $male,
                'gender_unknown' => count($class['persons']) - $female - $male, 'total' => count($class['persons']), 'repeaters' => $repeating, 'capacity' => $p->capacity,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, scalar|null>> */
    private function census(string $tenantId, string $establishmentId, DashboardPeriod $period, Roster $roster): array
    {
        $bands = $this->settings->ageBands($tenantId, $establishmentId);
        $reference = $period->yearStart ?? $period->today;
        $persons = $roster->persons();
        $people = $persons === [] ? [] : $this->demographics->ofPersons($tenantId, $persons);
        $cells = [];
        foreach ($roster->classes as $class) {
            foreach ($class['persons'] as $person) {
                $gender = $people[$person]['gender'] ?? null;
                $key = $class['profile']->levelCode . '|' . (in_array($gender, ['female', 'male'], true) ? $gender : 'unknown') . '|' . self::band($people[$person]['birth_date'] ?? null, $reference, $bands);
                $cells[$key] = ($cells[$key] ?? 0) + 1;
            }
        }
        ksort($cells);
        $rows = [];
        foreach ($cells as $key => $count) {
            [$level, $gender, $band] = explode('|', (string) $key);
            $rows[] = ['level' => $level, 'gender' => $gender, 'age_band' => $band, 'count' => $count];
        }

        return $rows;
    }

    /** @param list<int> $bands */
    private static function band(?string $birthDate, string $reference, array $bands): string
    {
        if ($birthDate === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $birthDate) !== 1) {
            return 'unknown';
        }
        $age = (new \DateTimeImmutable(substr($birthDate, 0, 10)))->diff(new \DateTimeImmutable(substr($reference, 0, 10)))->y;
        $low = null;
        foreach ($bands as $upper) {
            if ($age <= $upper) {
                return $low === null ? '<=' . $upper : ($low + 1) . '-' . $upper;
            }
            $low = $upper;
        }

        return '>' . (string) end($bands);
    }

    /** @return list<array<string, scalar|null>> */
    private function staffRows(string $tenantId, string $establishmentId): array
    {
        $cells = [];
        foreach ($this->staff->membersOf($tenantId, $establishmentId) as $member) {
            $key = $member->kind . '|' . ($member->category ?? '') . '|' . ($member->teaches ? 'oui' : 'non');
            $cells[$key] = ($cells[$key] ?? 0) + 1;
        }
        ksort($cells);
        $rows = [];
        foreach ($cells as $key => $count) {
            [$kind, $category, $teaches] = explode('|', (string) $key);
            $rows[] = ['kind' => $kind, 'category' => $category === '' ? null : $category, 'teaches' => $teaches, 'count' => $count];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, scalar|null>>
     */
    private function annualLevels(string $tenantId, string $establishmentId, DashboardPeriod $period, Roster $roster, array $query): array
    {
        $current = $this->levelResults($tenantId, $establishmentId, $period, $roster);
        $previous = [];
        $previousYear = DashboardService::reference($query, 'previous_year_id');
        if ($previousYear !== null) {
            [, $previousPeriod, $previousRoster] = $this->dashboards->frame($tenantId, $establishmentId, ['academic_year_id' => $previousYear]);
            $previous = $this->levelResults($tenantId, $establishmentId, $previousPeriod, $previousRoster);
        }
        $rows = [];
        foreach ($current as $level => $row) {
            $rows[] = $row + [
                'previous_students' => $previous[$level]['students'] ?? null, 'previous_average' => $previous[$level]['average'] ?? null,
                'previous_pass_rate' => $previous[$level]['pass_rate'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Résultats annuels enregistrés (période « année », sinon dernier trimestre) agrégés par niveau, sans nom : contrat Assessment
     * CouncilDirectory::storedResults. « Admis » = moyenne au moins égale à la moyenne de passage paramétrée.
     *
     * @return array<string, array{level: string, classes: int, students: int, with_average: int, average: ?float, passed: int, pass_rate: ?float}>
     */
    private function levelResults(string $tenantId, string $establishmentId, DashboardPeriod $period, Roster $roster): array
    {
        $final = $period->yearPeriod ?? ($period->terms === [] ? null : $period->terms[count($period->terms) - 1]);
        $passing = $this->settings->passingAverage($tenantId, $establishmentId);
        $levels = [];
        foreach ($roster->classes as $class) {
            $level = $class['profile']->levelCode;
            $levels[$level] ??= ['level' => $level, 'classes' => 0, 'students' => 0, 'with_average' => 0, 'sum' => 0.0, 'passed' => 0];
            ++$levels[$level]['classes'];
            $levels[$level]['students'] += count($class['persons']);
            if ($final === null) {
                continue;
            }
            foreach ($this->councils->storedResults($tenantId, $establishmentId, $class['profile']->structureId, $final->id) as $result) {
                if ($result['average'] === null) {
                    continue;
                }
                ++$levels[$level]['with_average'];
                $levels[$level]['sum'] += $result['average'];
                $levels[$level]['passed'] += $result['average'] >= $passing ? 1 : 0;
            }
        }
        $out = [];
        foreach ($levels as $level => $row) {
            $n = $row['with_average'];
            $out[(string) $level] = [
                'level' => $row['level'], 'classes' => $row['classes'], 'students' => $row['students'], 'with_average' => $n,
                'average' => $n === 0 ? null : round($row['sum'] / $n, 2), 'passed' => $row['passed'], 'pass_rate' => $n === 0 ? null : round(100 * $row['passed'] / $n, 1),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, scalar|null>>
     */
    private function annualExams(string $tenantId, string $establishmentId, DashboardPeriod $period, array $query): array
    {
        $byCode = static function (array $sessions): array {
            $out = [];
            foreach ($sessions as $s) {
                $code = $s['exam_code'];
                $out[$code] ??= ['exam' => $s['exam_label'], 'exam_code' => $code, 'candidates' => 0, 'results' => 0, 'passed' => 0];
                $out[$code]['candidates'] += $s['candidates'];
                $out[$code]['results'] += $s['results'];
                $out[$code]['passed'] += $s['passed'];
            }

            return $out;
        };
        $current = $byCode($this->exams->forYear($tenantId, $establishmentId, $period->academicYearId));
        $previousYear = DashboardService::reference($query, 'previous_year_id');
        $previous = $previousYear === null ? [] : $byCode($this->exams->forYear($tenantId, $establishmentId, $previousYear));
        $rows = [];
        foreach ($current as $code => $row) {
            $before = $previous[$code] ?? null;
            $rows[] = $row + [
                'pass_rate' => $row['results'] === 0 ? null : round(100 * $row['passed'] / $row['results'], 1),
                'previous_candidates' => $before['candidates'] ?? null,
                'previous_pass_rate' => $before === null || $before['results'] === 0 ? null : round(100 * $before['passed'] / $before['results'], 1),
            ];
        }

        return $rows;
    }
}
