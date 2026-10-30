<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Perimeter;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\EstablishmentBalances;

/**
 * Tableau de bord du directeur (EX-PRI-43, 44) : par classe, l'effectif, les absents du jour par demi-journée (appels validés), les
 * élèves en retard de paiement et leur solde, le suivi de saisie des feuilles de compétences. Agrégats seulement : aucun identifiant ni
 * nom d'élève. Les classes sont limitées au périmètre de `primary.dashboard.read` de la personne. Aucun seuil dans le code.
 */
final class DirectorDashboard
{
    public const READ = ['primary.dashboard.read'];

    public function __construct(
        private readonly Access $access,
        private readonly ClassFigures $classes,
        private readonly SheetFollowUp $sheets,
        private readonly DailyPresence $presence,
        private readonly DebtorDirectory $debtors,
        private readonly EstablishmentBalances $balances,
        private readonly PrimaryClock $clock,
        private readonly PrimarySettings $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(Principal $principal, string $establishmentId): array
    {
        return $this->compute($principal->tenantId, $establishmentId, $this->access->require($principal, $establishmentId, self::READ));
    }

    /**
     * Calcul sans utilisateur (fournisseur d'indicateurs du socle) : le contrôle d'accès est fait par l'appelant ; `null` = toutes les classes.
     *
     * @return array<string, mixed>
     */
    public function compute(string $t, string $establishmentId, ?Perimeter $perimeter): array
    {
        $today = $this->clock->today();
        $context = $this->sheets->context($t, $establishmentId, $today);
        $balances = $this->balances->balancesXaf($t, $establishmentId);
        $arrears = array_flip($this->debtors->learnerIdsInArrears($t, $establishmentId));
        $cap = $this->settings->int($t, $establishmentId, 'primary.class_size_cap');

        $rows = [];
        $totals = ['classes' => 0, 'learners' => 0, 'absent_morning' => 0, 'absent_afternoon' => 0, 'late_payers' => 0, 'late_balance_xaf' => 0, 'sheets_late' => 0];
        foreach ($this->classes->all($t, $establishmentId) as $class) {
            if ($perimeter !== null && !$perimeter->coversClass($class->id)) {
                continue;
            }
            $late = array_values(array_filter($class->learnerIds, static fn (string $id): bool => isset($arrears[$id])));
            $lateBalance = array_sum(array_map(static fn (string $id): int => max(0, $balances[$id] ?? 0), $late));
            $sheet = $this->sheets->forClass($t, $establishmentId, $class, $context);
            $row = [
                'class_id' => $class->id, 'name' => $class->name, 'level_id' => $class->levelId,
                'capacity' => $class->capacity, 'learners' => count($class->learnerIds),
                'over_capacity' => $class->capacity !== null && count($class->learnerIds) > $class->capacity,
                'class_size_cap' => $cap, 'over_cap' => $cap > 0 && count($class->learnerIds) > $cap,
                'absent_morning' => count($this->presence->absentOn($t, $establishmentId, $today, $class->id, DailyPresence::MORNING)),
                'absent_afternoon' => count($this->presence->absentOn($t, $establishmentId, $today, $class->id, DailyPresence::AFTERNOON)),
                'late_payers' => count($late), 'late_balance_xaf' => $lateBalance,
                'sheet' => $sheet,
            ];
            $rows[] = $row;
            ++$totals['classes'];
            $totals['learners'] += $row['learners'];
            $totals['absent_morning'] += $row['absent_morning'];
            $totals['absent_afternoon'] += $row['absent_afternoon'];
            $totals['late_payers'] += $row['late_payers'];
            $totals['late_balance_xaf'] += $lateBalance;
            $totals['sheets_late'] += $sheet['late'] ? 1 : 0;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $period = $context['period'];

        return [
            'date' => $today,
            'period' => $period === null ? null : ['id' => $period->id, 'kind' => $period->kind, 'starts_on' => $period->startsOn, 'ends_on' => $period->endsOn, 'academic_year_id' => $period->academicYearId],
            'perimeter' => $perimeter === null || $perimeter->wholeEstablishment ? 'establishment' : 'classes',
            'totals' => $totals,
            'classes' => $rows,
            'meta' => [
                'absences' => 'Appels validés du jour, par demi-journée ; une classe dont l\'appel n\'est pas fait compte zéro absent.',
                'deadlines_setting' => SheetFollowUp::DEADLINES_KEY,
            ],
        ];
    }
}
