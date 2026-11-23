<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamOutcomes;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;
use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;

/**
 * Tableaux de bord par rôle (CDC « Matrice des droits », ligne Tableaux de bord) :
 *  - chef d'établissement (`head`) : tout l'établissement, objectifs O1 à O7 (O4 et O5 seulement avec un droit des finances), effectifs,
 *    personnel, examens ;
 *  - censeur (`pedagogy`) : O1, O2, O6, O7 et le tableau des classes ; un professeur principal ou un enseignant n'y voit que les
 *    classes de son périmètre ;
 *  - intendant (`finance`) : O4, O5, totaux facturés et recouvrement par classe.
 * Tout passe par les contrats des sous-modules et du socle ; jamais de donnée nominative.
 */
final class DashboardService
{
    public const READ = ['secondary.dashboard.read'];
    public const FINANCE = ['secondary.finance.read', 'secondary.fees.read', 'secondary.billing.manage'];
    public const PEDAGOGY_CODES = ['O1', 'O2', 'O6', 'O7'];

    public function __construct(
        private readonly Access $access,
        private readonly PeriodResolver $periods,
        private readonly ObjectiveCalculator $objectives,
        private readonly EstablishmentProfiles $profiles,
        private readonly ClassProfiles $classes,
        private readonly StructureEnrollments $enrollments,
        private readonly PersonDemographics $demographics,
        private readonly EstablishmentStaffRoster $staff,
        private readonly ExamOutcomes $exams,
        private readonly SecondaryClock $clock,
        private readonly DashboardSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function head(Principal $principal, string $establishmentId, array $query): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertWhole($principal, $perimeter, 'secondary_dashboard');
        [$profile, $period, $roster] = $this->frame($principal->tenantId, $establishmentId, $query);
        $finance = !$this->access->perimeter($principal, $establishmentId, self::FINANCE)->isEmpty();
        $codes = $finance ? ['O1', 'O2', 'O3', 'O4', 'O5', 'O6', 'O7'] : ['O1', 'O2', 'O3', 'O6', 'O7'];
        $computed = $this->objectives->compute($principal->tenantId, $establishmentId, $profile, $period, $roster, $codes);

        return $this->envelope('head', $profile, $period, $computed['objectives']) + [
            'restricted' => $finance ? [] : ObjectiveCalculator::FINANCE,
            'figures' => $this->figures($principal->tenantId, $establishmentId, $profile, $period, $roster),
            'alerts' => $this->alerts($principal->tenantId, $establishmentId, $period),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function pedagogy(Principal $principal, string $establishmentId, array $query): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        [$profile, $period, $roster] = $this->frame($principal->tenantId, $establishmentId, $query);
        if (!$perimeter->wholeEstablishment) {
            $roster = $roster->only($perimeter->classIds);
        }
        $computed = $this->objectives->compute($principal->tenantId, $establishmentId, $profile, $period, $roster, self::PEDAGOGY_CODES);

        return $this->envelope('pedagogy', $profile, $period, $computed['objectives']) + [
            'scope' => $perimeter->wholeEstablishment ? 'establishment' : 'classes',
            'classes' => array_values($computed['classes']),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function finance(Principal $principal, string $establishmentId, array $query): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::FINANCE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_dashboard');
        [$profile, $period, $roster] = $this->frame($principal->tenantId, $establishmentId, $query);
        $computed = $this->objectives->compute($principal->tenantId, $establishmentId, $profile, $period, $roster, ObjectiveCalculator::FINANCE);
        $rows = [];
        $totals = ['net_xaf' => 0, 'paid_xaf' => 0, 'balance_xaf' => 0, 'overdue_xaf' => 0, 'invoices' => 0, 'students_invoiced' => 0];
        foreach ($roster->classes as $class) {
            $figures = $this->objectives->finance($principal->tenantId, $establishmentId, $period, $class['persons']);
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $figures[$key];
            }
            $rows[] = [
                'class_id' => $class['profile']->structureId, 'label' => $class['profile']->label, 'level_code' => $class['profile']->levelCode, 'students' => count($class['persons']),
                'recovery_percent' => $figures['net_xaf'] === 0 ? null : round(100 * $figures['paid_xaf'] / $figures['net_xaf'], 1),
            ] + $figures;
        }

        return $this->envelope('finance', $profile, $period, $computed['objectives']) + [
            'fees_mode' => match (true) {
                ObjectiveCalculator::usable($profile, Modules::FEES_PRIVATE) => 'private',
                ObjectiveCalculator::usable($profile, Modules::FEES_PUBLIC_CATALOG) => 'public_catalog',
                default => 'none',
            },
            'totals' => $totals + ['recovery_percent' => $totals['net_xaf'] === 0 ? null : round(100 * $totals['paid_xaf'] / $totals['net_xaf'], 1)],
            'classes' => $rows,
        ];
    }

    /**
     * Profil, cadre temporel et classes de l'année : base commune des tableaux de bord et des rapports.
     *
     * @param array<string, mixed> $query
     * @return array{0: EstablishmentProfile, 1: DashboardPeriod, 2: Roster}
     */
    public function frame(string $tenantId, string $establishmentId, array $query): array
    {
        $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        $period = $this->periods->resolve($tenantId, $establishmentId, self::reference($query, 'academic_year_id'), self::reference($query, 'period_id'));

        return [$profile, $period, Roster::load($this->classes, $this->enrollments, $tenantId, $establishmentId, $period->academicYearId)];
    }

    /**
     * Effectifs par sexe, personnel par statut, examens officiels de l'année (comptes seulement).
     *
     * @return array<string, mixed>
     */
    public function figures(string $tenantId, string $establishmentId, EstablishmentProfile $profile, DashboardPeriod $period, Roster $roster): array
    {
        $persons = $roster->persons();
        $sexes = ['female' => 0, 'male' => 0, 'gender_unknown' => 0];
        foreach ($persons === [] ? [] : $this->demographics->ofPersons($tenantId, $persons) as $person) {
            if ($person['gender'] === 'female' || $person['gender'] === 'male') {
                ++$sexes[$person['gender']];
            }
        }
        $sexes['gender_unknown'] = count($persons) - $sexes['female'] - $sexes['male'];
        $byKind = [];
        $teaching = 0;
        $members = $this->staff->membersOf($tenantId, $establishmentId);
        foreach ($members as $member) {
            $byKind[$member->kind] = ($byKind[$member->kind] ?? 0) + 1;
            $teaching += $member->teaches ? 1 : 0;
        }
        $exams = [];
        if (ObjectiveCalculator::usable($profile, Modules::OFFICIAL_EXAMS)) {
            foreach ($this->exams->forYear($tenantId, $establishmentId, $period->academicYearId) as $session) {
                $exams[] = $session + ['pass_rate' => $session['results'] === 0 ? null : round(100 * $session['passed'] / $session['results'], 1)];
            }
        }

        return [
            'classes' => count($roster->classes), 'students' => count($persons)] + $sexes + [
            'staff' => ['total' => count($members), 'teaching' => $teaching, 'by_kind' => $byKind],
            'exams' => $exams,
        ];
    }

    /**
     * RG-BUL-4 : échéance de transmission des données du conseil de fin d'année à cartescolaire.cm, si le paramètre est renseigné
     * (`secondary.carte_scolaire.year_end_deadline`). Le fichier lui-même (DOC-33, API-04) est produit par CarteScolaire.
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(string $tenantId, string $establishmentId, DashboardPeriod $period): array
    {
        $deadline = $this->settings->yearEndDeadline($tenantId, $establishmentId);
        if ($deadline === null) {
            return [];
        }
        $days = (int) (new \DateTimeImmutable($period->today))->diff(new \DateTimeImmutable($deadline))->format('%r%a');

        return [['code' => 'RG-BUL-4', 'deadline' => $deadline, 'days_left' => $days, 'status' => $days < 0 ? 'overdue' : 'upcoming']];
    }

    /**
     * @param list<array<string, mixed>> $objectives
     * @return array<string, mixed>
     */
    private function envelope(string $kind, EstablishmentProfile $profile, DashboardPeriod $period, array $objectives): array
    {
        return [
            'kind' => $kind, 'head_title' => $profile->headTitle, 'period' => $period->toArray(), 'objectives' => $objectives,
            'generated_at' => $this->clock->now(), 'nominative' => false,
        ];
    }

    /** @param array<string, mixed> $query */
    public static function reference(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
            throw HttpException::validation([$name => ['Identifiant invalide.']]);
        }

        return $value;
    }
}
