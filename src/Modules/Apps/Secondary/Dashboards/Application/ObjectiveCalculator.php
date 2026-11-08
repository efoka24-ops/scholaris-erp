<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIdCoverage;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ReportCardIssuance;
use Scholaris\Modules\Apps\Secondary\Dashboards\Domain\DashboardDefaults;
use Scholaris\Modules\Apps\Secondary\Fees\Contracts\FeeCompliance;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageReader;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceTotals;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;

/**
 * Objectifs O1 à O7 du CDC (§ Objectifs), calculés UNIQUEMENT par les contrats des sous-modules et du socle, jamais par leurs tables :
 *  - O1 réclamations fondées sur une erreur de calcul (registre des réclamations des tableaux de bord) ;
 *  - O2 bulletins remis au plus tard N jours après la dernière séquence du trimestre (Councils ReportCardIssuance, Organisation) ;
 *  - O3 élèves dont le matricule cartescolaire est enregistré, condition d'une transmission sans ressaisie (CarteScolaire) ;
 *  - O4 pension recouvrée, établissements privés (Billing LearnerInvoices et InvoiceTotals) ;
 *  - O5 frais hors catalogue tentés et bloqués, établissements publics (Fees FeeCompliance) ;
 *  - O6 classes dont le taux de couverture des programmes est connu (Syllabus CoverageReader) ;
 *  - O7 heures d'absence injustifiées par élève sur le trimestre et baisse par rapport au trimestre précédent (Attendance).
 * Un objectif sans donnée ou sans objet est rendu `available: false` avec un motif : jamais de chiffre inventé.
 */
final class ObjectiveCalculator
{
    public const LABELS = [
        'O1' => 'Réclamations fondées sur une erreur de calcul',
        'O2' => 'Bulletins remis dans le délai après la dernière séquence',
        'O3' => 'Élèves avec matricule cartescolaire enregistré',
        'O4' => 'Pension recouvrée',
        'O5' => 'Frais hors catalogue autorisé tentés (bloqués)',
        'O6' => 'Classes avec un taux de couverture des programmes connu',
        'O7' => 'Heures d\'absence injustifiées par élève sur le trimestre',
    ];
    public const FINANCE = ['O4', 'O5'];
    private const INVOICE_BATCH = 500;

    public function __construct(
        private readonly DashboardSettings $settings,
        private readonly ClaimRegister $claims,
        private readonly ReportCardIssuance $reportCards,
        private readonly NationalIdCoverage $nationalIds,
        private readonly FeeCompliance $fees,
        private readonly CoverageReader $coverage,
        private readonly LearnerInvoices $invoices,
        private readonly InvoiceTotals $invoiceTotals,
        private readonly AttendanceTotals $attendance,
    ) {
    }

    /**
     * @param list<string> $codes objectifs demandés (O1 à O7)
     * @return array{objectives: list<array<string, mixed>>, classes: array<string, array<string, mixed>>}
     */
    public function compute(string $tenantId, string $establishmentId, EstablishmentProfile $profile, DashboardPeriod $period, Roster $roster, array $codes = ['O1', 'O2', 'O3', 'O4', 'O5', 'O6', 'O7']): array
    {
        $targets = $this->settings->targets($tenantId, $establishmentId);
        $classes = [];
        foreach ($roster->classes as $class) {
            $classes[$class['profile']->structureId] = ['class_id' => $class['profile']->structureId, 'label' => $class['profile']->label, 'level_code' => $class['profile']->levelCode, 'students' => count($class['persons'])];
        }
        $objectives = [];
        foreach ($codes as $code) {
            $result = match ($code) {
                'O1' => $this->o1($tenantId, $establishmentId, $period, $roster),
                'O2' => $this->o2($tenantId, $establishmentId, $period, $roster, $classes),
                'O3' => $this->o3($tenantId, $establishmentId, $roster),
                'O4' => $this->o4($tenantId, $establishmentId, $profile, $period, $roster),
                'O5' => $this->o5($tenantId, $establishmentId, $profile, $period),
                'O6' => $this->o6($tenantId, $establishmentId, $period, $roster, $classes),
                'O7' => $this->o7($tenantId, $establishmentId, $period, $roster, $classes),
                default => null,
            };
            if ($result !== null) {
                $objectives[] = self::shape($code, $targets[$code], $result);
            }
        }

        return ['objectives' => $objectives, 'classes' => $classes];
    }

    /** @return array<string, mixed> */
    private function o1(string $tenantId, string $establishmentId, DashboardPeriod $period, Roster $roster): array
    {
        $counts = $this->claims->counts($tenantId, $establishmentId, $period->academicYearId, $roster->structureIds());

        return ['value' => $counts[ClaimRegister::CALCULATION_ERROR], 'detail' => $counts];
    }

    /**
     * @param array<string, array<string, mixed>> $classes
     * @return array<string, mixed>
     */
    private function o2(string $tenantId, string $establishmentId, DashboardPeriod $period, Roster $roster, array &$classes): array
    {
        $term = $period->term;
        if ($term === null) {
            return ['reason' => 'no_term'];
        }
        $days = $this->settings->reportCardDays($tenantId, $establishmentId);
        $deadline = self::addDays($period->lastSequenceEndOf($term), $days);
        $issued = [];
        foreach ($this->reportCards->issuedFor($tenantId, $establishmentId, [$term->id]) as $row) {
            $issued[$row['structure_id']] = substr($row['first_issued_at'], 0, 10);
        }
        $due = 0;
        $onTime = 0;
        $late = 0;
        $missing = 0;
        foreach ($roster->structureIds() as $classId) {
            $date = $issued[$classId] ?? null;
            $classes[$classId]['report_cards_issued_on'] = $date;
            $classes[$classId]['report_cards_on_time'] = $date === null ? null : $date <= $deadline;
            if ($date === null && $deadline >= $period->today) {
                continue;
            }
            ++$due;
            match (true) {
                $date === null => ++$missing,
                $date <= $deadline => ++$onTime,
                default => ++$late,
            };
        }
        $detail = ['term_id' => $term->id, 'deadline' => $deadline, 'days_after_last_sequence' => $days, 'classes' => count($roster->classes), 'due' => $due, 'on_time' => $onTime, 'late' => $late, 'missing' => $missing];
        if ($due === 0) {
            return ['reason' => 'not_due', 'detail' => $detail];
        }

        return ['value' => self::percent($onTime, $due), 'detail' => $detail];
    }

    /** @return array<string, mixed> */
    private function o3(string $tenantId, string $establishmentId, Roster $roster): array
    {
        $persons = $roster->persons();
        if ($persons === []) {
            return ['reason' => 'no_students'];
        }
        $statuses = $this->nationalIds->statusesOf($tenantId, $establishmentId, $persons);
        $recorded = count(array_filter($statuses, static fn (string $s): bool => $s === 'recorded'));
        $mismatch = count(array_filter($statuses, static fn (string $s): bool => $s === 'mismatch'));

        return ['value' => self::percent($recorded, count($persons)), 'detail' => ['students' => count($persons), 'recorded' => $recorded, 'mismatch' => $mismatch, 'pending' => count($persons) - $recorded - $mismatch]];
    }

    /** @return array<string, mixed> */
    private function o4(string $tenantId, string $establishmentId, EstablishmentProfile $profile, DashboardPeriod $period, Roster $roster): array
    {
        if (!self::usable($profile, Modules::FEES_PRIVATE)) {
            return ['reason' => 'not_applicable'];
        }
        $totals = $this->finance($tenantId, $establishmentId, $period, $roster->persons());
        if ($totals['net_xaf'] === 0) {
            return ['reason' => 'no_invoices', 'detail' => $totals];
        }

        return ['value' => self::percent($totals['paid_xaf'], $totals['net_xaf']), 'detail' => $totals];
    }

    /** @return array<string, mixed> */
    private function o5(string $tenantId, string $establishmentId, EstablishmentProfile $profile, DashboardPeriod $period): array
    {
        if (!self::usable($profile, Modules::FEES_PUBLIC_CATALOG)) {
            return ['reason' => 'not_applicable'];
        }
        if ($period->yearStart === null || $period->yearEnd === null) {
            return ['reason' => 'no_calendar'];
        }
        $counts = $this->fees->requestCounts($tenantId, $establishmentId, $period->yearStart, $period->yearEnd);

        return ['value' => $counts['blocked'], 'detail' => $counts + ['collected_out_of_catalog_guarded' => true]];
    }

    /**
     * @param array<string, array<string, mixed>> $classes
     * @return array<string, mixed>
     */
    private function o6(string $tenantId, string $establishmentId, DashboardPeriod $period, Roster $roster, array &$classes): array
    {
        if ($roster->classes === []) {
            return ['reason' => 'no_classes'];
        }
        $known = 0;
        $rates = [];
        foreach ($roster->structureIds() as $classId) {
            $classRates = [];
            foreach ($this->coverage->coverage($tenantId, $establishmentId, $period->academicYearId, $classId) as $line) {
                if ($line->rate !== null) {
                    $classRates[] = $line->rate;
                }
            }
            $average = $classRates === [] ? null : round(100 * array_sum($classRates) / count($classRates), 1);
            $classes[$classId]['coverage_percent'] = $average;
            if ($average !== null) {
                ++$known;
                $rates[] = $average;
            }
        }

        return ['value' => self::percent($known, count($roster->classes)), 'detail' => [
            'classes' => count($roster->classes), 'known' => $known, 'average_coverage_percent' => $rates === [] ? null : round(array_sum($rates) / count($rates), 1),
        ]];
    }

    /**
     * @param array<string, array<string, mixed>> $classes
     * @return array<string, mixed>
     */
    private function o7(string $tenantId, string $establishmentId, DashboardPeriod $period, Roster $roster, array &$classes): array
    {
        $term = $period->term;
        if ($term === null) {
            return ['reason' => 'no_term'];
        }
        $persons = $roster->persons();
        if ($persons === []) {
            return ['reason' => 'no_students'];
        }
        $current = $this->hours($tenantId, $establishmentId, $term, $period->today);
        foreach ($roster->classes as $class) {
            $sum = 0;
            foreach ($class['persons'] as $person) {
                $sum += $current[$person] ?? 0;
            }
            $classes[$class['profile']->structureId]['unjustified_hours_per_student'] = $class['persons'] === [] ? null : round($sum / count($class['persons']), 2);
        }
        $value = round(self::sumFor($current, $persons) / count($persons), 2);
        $detail = ['term_id' => $term->id, 'students' => count($persons), 'unjustified_hours' => self::sumFor($current, $persons), 'baseline_term_id' => null, 'baseline_hours_per_student' => null, 'change_percent' => null];
        if ($period->previousTerm !== null) {
            $baseline = $this->hours($tenantId, $establishmentId, $period->previousTerm, $period->today);
            $base = round(self::sumFor($baseline, $persons) / count($persons), 2);
            $detail['baseline_term_id'] = $period->previousTerm->id;
            $detail['baseline_hours_per_student'] = $base;
            $detail['change_percent'] = $base > 0 ? round(100 * ($value - $base) / $base, 1) : null;
        }

        return ['value' => $value, 'detail' => $detail];
    }

    /**
     * Totaux facturés de l'année (O4 et tableau de bord de l'intendant), par lots de factures.
     *
     * @param list<string> $persons
     * @return array{net_xaf: int, paid_xaf: int, balance_xaf: int, overdue_xaf: int, invoices: int, students_invoiced: int}
     */
    public function finance(string $tenantId, string $establishmentId, DashboardPeriod $period, array $persons): array
    {
        $ids = [];
        $invoiced = 0;
        foreach ($persons as $person) {
            $own = $this->invoices->idsFor($tenantId, $establishmentId, $person);
            $invoiced += $own === [] ? 0 : 1;
            array_push($ids, ...$own);
        }
        $out = ['net_xaf' => 0, 'paid_xaf' => 0, 'balance_xaf' => 0, 'overdue_xaf' => 0, 'invoices' => count($ids), 'students_invoiced' => $invoiced];
        foreach (array_chunk(array_values(array_unique($ids)), self::INVOICE_BATCH) as $chunk) {
            foreach ($this->invoiceTotals->totals($tenantId, $establishmentId, $chunk, $period->today) as $totals) {
                foreach (['net_xaf', 'paid_xaf', 'balance_xaf', 'overdue_xaf'] as $key) {
                    $out[$key] += $totals[$key];
                }
            }
        }

        return $out;
    }

    /** @return array<string, int> heures injustifiées par élève (une séance d'appel validée = une heure, E2-B) */
    private function hours(string $tenantId, string $establishmentId, PeriodInfo $term, string $today): array
    {
        $to = min($term->endsOn, $today);
        if ($term->startsOn > $to) {
            return [];
        }
        $out = [];
        foreach ($this->attendance->byLearner($tenantId, $establishmentId, $term->startsOn, $to) as $person => $summary) {
            $out[(string) $person] = $summary->unjustifiedAbsences;
        }

        return $out;
    }

    /**
     * @param array<string, int> $values
     * @param list<string> $persons
     */
    private static function sumFor(array $values, array $persons): int
    {
        $sum = 0;
        foreach ($persons as $person) {
            $sum += $values[$person] ?? 0;
        }

        return $sum;
    }

    public static function usable(EstablishmentProfile $profile, string $module): bool
    {
        return $profile->isActive($module) || $profile->isReadOnly($module);
    }

    /**
     * @param array{target: int|float, direction: string, unit: string} $target
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function shape(string $code, array $target, array $result): array
    {
        $value = $result['value'] ?? null;
        $available = is_int($value) || is_float($value);
        $detail = is_array($result['detail'] ?? null) ? $result['detail'] : [];
        $met = null;
        if ($available) {
            $met = match ($target['direction']) {
                'max' => $value <= $target['target'],
                'min' => $value >= $target['target'],
                default => is_numeric($detail['change_percent'] ?? null) ? -1 * (float) $detail['change_percent'] >= $target['target'] : null,
            };
        }

        return [
            'code' => $code, 'label' => self::LABELS[$code], 'available' => $available, 'value' => $available ? $value : null, 'unit' => $target['unit'],
            'target' => $target['target'], 'direction' => $target['direction'], 'met' => $met, 'reason' => $available ? null : (string) ($result['reason'] ?? 'unavailable'),
            'detail' => $detail, 'source' => DashboardDefaults::SOURCE, 'confirmation' => DashboardDefaults::CONFIRMATION,
        ];
    }

    private static function percent(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round(100 * $part / $whole, 1);
    }

    private static function addDays(string $day, int $days): string
    {
        return (new \DateTimeImmutable(substr($day, 0, 10)))->modify('+' . $days . ' days')->format('Y-m-d');
    }
}
