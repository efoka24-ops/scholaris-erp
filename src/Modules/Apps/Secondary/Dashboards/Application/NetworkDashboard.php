<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamOutcomes;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\Reporting\Contracts\DisclosurePolicy;
use Throwable;

/**
 * Vue réseau (R02 `sec_reseau`, RG-ORG-5, Q6) : effectifs, effectifs par sexe, taux de réussite aux examens officiels et taux de
 * recouvrement des établissements du client où la personne a `secondary.network.read` ET dont le profil a le module
 * `network_indicators`. AGRÉGATS SEULEMENT, jamais un nom ni un identifiant de personne. Petites cases masquées comme dans le
 * reste du produit : effectif sous le seuil `reporting.disclosure.minimum_cell_size` masqué (DisclosurePolicy du socle), masquage
 * secondaire pour qu'une case masquée ne se déduise pas du total, taux masqué quand sa base est sous le seuil.
 */
final class NetworkDashboard
{
    public const PERMISSION = 'secondary.network.read';

    public function __construct(
        private readonly Access $access,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentDirectory $establishments,
        private readonly EstablishmentProfiles $profiles,
        private readonly DashboardService $dashboards,
        private readonly ObjectiveCalculator $objectives,
        private readonly PersonDemographics $demographics,
        private readonly ExamOutcomes $exams,
        private readonly DisclosurePolicy $disclosure,
        private readonly SecondaryClock $clock,
        private readonly int $minimumCellSize,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function build(Principal $principal, string $establishmentId, array $query): array
    {
        $this->access->require($principal, $establishmentId, [self::PERMISSION]);
        $raw = [];
        foreach ($this->establishments->forTenant($principal->tenantId) as $establishment) {
            if ($establishment->type !== 'secondary' || !$this->permissions->can($principal, self::PERMISSION, $establishment->id)) {
                continue;
            }
            try {
                $profile = $this->profiles->forEstablishment($principal->tenantId, $establishment->id);
                if (!ObjectiveCalculator::usable($profile, Modules::NETWORK_INDICATORS)) {
                    continue;
                }
                $raw[] = ['name' => $establishment->name] + $this->figures($principal->tenantId, $establishment->id, $query, ObjectiveCalculator::usable($profile, Modules::FEES_PRIVATE));
            } catch (Throwable) {
                continue;
            }
        }

        return [
            'kind' => 'network', 'nominative' => false, 'minimum_cell_size' => $this->minimumCellSize, 'generated_at' => $this->clock->now(),
            'establishments' => $this->mask($raw), 'consolidated' => $this->consolidate($raw),
        ];
    }

    /**
     * Comptes bruts d'un établissement (jamais rendus tels quels).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function figures(string $tenantId, string $establishmentId, array $query, bool $private): array
    {
        [, $period, $roster] = $this->dashboards->frame($tenantId, $establishmentId, ['academic_year_id' => $query['academic_year_id'] ?? null]);
        $persons = $roster->persons();
        $female = 0;
        $male = 0;
        foreach ($persons === [] ? [] : $this->demographics->ofPersons($tenantId, $persons) as $person) {
            $female += $person['gender'] === 'female' ? 1 : 0;
            $male += $person['gender'] === 'male' ? 1 : 0;
        }
        $results = 0;
        $passed = 0;
        foreach ($this->exams->forYear($tenantId, $establishmentId, $period->academicYearId) as $session) {
            $results += $session['results'];
            $passed += $session['passed'];
        }
        $finance = $private ? $this->objectives->finance($tenantId, $establishmentId, $period, $persons) : null;

        return [
            'academic_year_id' => $period->academicYearId, 'students' => count($persons), 'female' => $female, 'male' => $male,
            'gender_unknown' => count($persons) - $female - $male, 'exam_results' => $results, 'exam_passed' => $passed,
            'invoiced_students' => $finance['students_invoiced'] ?? 0, 'net_xaf' => $finance['net_xaf'] ?? 0, 'paid_xaf' => $finance['paid_xaf'] ?? 0,
            'private' => $private,
        ];
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return list<array<string, mixed>>
     */
    private function mask(array $raw): array
    {
        $min = $this->minimumCellSize;
        $studentCells = [];
        $primary = [];
        foreach ($raw as $index => $row) {
            $studentCells['e' . $index] = (int) $row['students'];
            if ($this->disclosure->suppressPrimary((int) $row['students'], $min)) {
                $primary[] = 'e' . $index;
            }
        }
        $hiddenStudents = array_merge($primary, $this->disclosure->suppressSecondary($studentCells, $primary, $min));
        $out = [];
        foreach ($raw as $index => $row) {
            $masked = [];
            $students = in_array('e' . $index, $hiddenStudents, true) ? null : (int) $row['students'];
            $students === null && $masked[] = 'students';
            $cells = ['female' => (int) $row['female'], 'male' => (int) $row['male'], 'gender_unknown' => (int) $row['gender_unknown']];
            $sexPrimary = array_values(array_filter(array_keys($cells), fn (string $k): bool => $cells[$k] > 0 && $this->disclosure->suppressPrimary($cells[$k], $min)));
            $sexHidden = $students === null ? array_keys($cells) : array_merge($sexPrimary, $this->disclosure->suppressSecondary($cells, $sexPrimary, $min));
            $female = in_array('female', $sexHidden, true) ? null : $cells['female'];
            $male = in_array('male', $sexHidden, true) ? null : $cells['male'];
            $female === null && $masked[] = 'female';
            $male === null && $masked[] = 'male';
            $examRate = self::rate((int) $row['exam_passed'], (int) $row['exam_results'], $min);
            $recovery = $row['private'] === true ? self::rate((int) $row['paid_xaf'], (int) $row['net_xaf'], $min, (int) $row['invoiced_students']) : ['value' => null, 'masked' => false];
            $examRate['masked'] && $masked[] = 'exam_pass_rate';
            $recovery['masked'] && $masked[] = 'recovery_rate';
            $out[] = [
                'establishment' => (string) $row['name'], 'academic_year_id' => (string) $row['academic_year_id'], 'students' => $students, 'female' => $female, 'male' => $male,
                'exam_pass_rate' => $examRate['value'], 'recovery_rate' => $recovery['value'], 'recovery_applicable' => $row['private'] === true, 'masked' => $masked,
            ];
        }

        return $out;
    }

    /**
     * Totaux du réseau : l'effectif total est sûr (masquage secondaire appliqué aux établissements) ; filles et garçons ne sont donnés
     * que si aucun établissement n'a de case de sexe sous le seuil ; un taux ne l'est que si sa base totale atteint le seuil.
     *
     * @param list<array<string, mixed>> $raw
     * @return array<string, mixed>
     */
    private function consolidate(array $raw): array
    {
        $min = $this->minimumCellSize;
        $sum = static fn (string $key): int => array_sum(array_map(static fn (array $r): int => (int) $r[$key], $raw));
        $sexSafe = array_filter($raw, fn (array $r): bool => ($r['female'] > 0 && $this->disclosure->suppressPrimary((int) $r['female'], $min))
            || ($r['male'] > 0 && $this->disclosure->suppressPrimary((int) $r['male'], $min))) === [];
        $students = $sum('students');
        $private = array_values(array_filter($raw, static fn (array $r): bool => $r['private'] === true));
        $net = array_sum(array_map(static fn (array $r): int => (int) $r['net_xaf'], $private));
        $paid = array_sum(array_map(static fn (array $r): int => (int) $r['paid_xaf'], $private));
        $invoiced = array_sum(array_map(static fn (array $r): int => (int) $r['invoiced_students'], $private));

        return [
            'establishments' => count($raw),
            'students' => $raw === [] || $this->disclosure->suppressPrimary($students, $min) ? null : $students,
            'female' => $sexSafe && $raw !== [] ? $sum('female') : null,
            'male' => $sexSafe && $raw !== [] ? $sum('male') : null,
            'exam_pass_rate' => self::rate($sum('exam_passed'), $sum('exam_results'), $min)['value'],
            'recovery_rate' => $private === [] ? null : self::rate($paid, $net, $min, $invoiced)['value'],
        ];
    }

    /** @return array{value: ?float, masked: bool} taux en pourcentage, masqué si sa base (ou l'effectif donné) est sous le seuil */
    private static function rate(int $part, int $whole, int $min, ?int $base = null): array
    {
        if ($whole === 0) {
            return ['value' => null, 'masked' => false];
        }
        if (($base ?? $whole) < $min) {
            return ['value' => null, 'masked' => true];
        }

        return ['value' => round(100 * $part / $whole, 1), 'masked' => false];
    }
}
