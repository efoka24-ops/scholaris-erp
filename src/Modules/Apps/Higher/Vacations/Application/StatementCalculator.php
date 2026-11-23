<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TaughtSessionInfo;
use Scholaris\Modules\Apps\Higher\Vacations\Domain\Deductions;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Scholaris\Modules\Socle\Staff\Contracts\VacationCalculator;

/**
 * Calcul d'un état mensuel (TST-11) : chaque séance validée passe les conditions de payabilité du socle (RG-ENS-1, RG-ENS-4) ;
 * HETD par nature avec les coefficients paramétrés (D14), taux par grade et niveau (D15), brut entier XAF par niveau, retenues
 * paramétrables (aucune par défaut). Exemple : 18 h CM + 12 h TD + 6 h TP = 43,5 HETD ; × 6 000 = 261 000 FCFA.
 */
final class StatementCalculator
{
    private const KINDS = ['cm', 'td', 'tp', 'other'];

    public function __construct(
        private readonly VacationCalculator $calculator,
        private readonly OrgUnitTree $tree,
        private readonly SettingsReader $settings,
        private readonly PayabilityGate $gate,
    ) {
    }

    /**
     * @param list<TaughtSessionInfo> $sessions séances validées d'un département
     * @return array{totals: array<string, int|string|null>, lines: list<array<string, int|string|null>>}
     */
    public function compute(string $tenantId, string $establishmentId, ?StaffInfo $staff, array $sessions, string $actorId): array
    {
        $grade = $staff?->grade;
        $coefficients = $this->calculator->coefficients($tenantId, $establishmentId);
        $lines = [];
        $byLevel = [];
        $minutes = array_fill_keys(self::KINDS, 0);
        $excluded = 0;
        foreach ($sessions as $session) {
            $kind = in_array($session->sessionKind, self::KINDS, true) ? $session->sessionKind : 'other';
            $level = $this->tree->structure($tenantId, $establishmentId, $session->levelId)->name ?? $session->levelId;
            $refusals = $this->gate->hourRefusals(self::hour($tenantId, $establishmentId, $staff, $session, $level), $actorId);
            $rate = $grade === null ? null : $this->calculator->hourlyRate($tenantId, $establishmentId, $grade, $level);
            $payable = $refusals === [];
            if ($payable && $rate === null) {
                throw HttpException::conflict(sprintf('Aucun taux de vacation pour le grade « %s » au niveau %s (taux par grade et niveau à saisir).', $grade ?? 'non renseigné', $level));
            }
            if ($payable) {
                $minutes[$kind] += $session->minutes;
                $byLevel[$level . '|' . $rate][$kind] = ($byLevel[$level . '|' . $rate][$kind] ?? 0) + $session->minutes;
            } else {
                $excluded += $session->minutes;
            }
            $lines[] = [
                'session_log_id' => $session->id, 'assignment_id' => $session->assignmentId, 'school_id' => $session->schoolId,
                'department_id' => $session->departmentId, 'program_id' => $session->programId, 'level_id' => $session->levelId,
                'level_label' => $level, 'group_id' => $session->groupId, 'element_id' => $session->elementId,
                'element_code' => $session->elementCode, 'element_title' => $session->elementTitle, 'session_kind' => $kind,
                'held_on' => $session->heldOn, 'minutes' => $session->minutes,
                'hetd_milli' => $this->calculator->hetdMilli($tenantId, $establishmentId, [$kind => $session->minutes]),
                'hourly_rate' => $rate, 'payable' => $payable ? 1 : 0, 'refusal' => $payable ? null : implode(' ', $refusals),
            ];
        }
        $gross = 0;
        $rates = [];
        foreach ($byLevel as $key => $kinds) {
            $rate = (int) explode('|', $key)[1];
            $rates[$rate] = true;
            $gross += $this->calculator->amount($this->calculator->hetdMilli($tenantId, $establishmentId, $kinds), $rate);
        }
        $deductions = Deductions::apply($this->settings->value($tenantId, $establishmentId, VacationPermissions::DEDUCTIONS, []), $gross);
        $totals = [
            'grade' => $grade, 'staff_kind' => $staff?->kind,
            'coef_cm' => $coefficients['cm'], 'coef_td' => $coefficients['td'], 'coef_tp' => $coefficients['tp'], 'coef_other' => $coefficients['other'],
            'hetd_milli' => $this->calculator->hetdMilli($tenantId, $establishmentId, $minutes),
            'hourly_rate' => count($rates) === 1 ? (int) array_key_first($rates) : null,
            'gross_amount' => $gross, 'deductions' => json_encode($deductions['lines'], JSON_THROW_ON_ERROR),
            'deductions_amount' => $deductions['total'], 'net_amount' => $gross - $deductions['total'], 'excluded_minutes' => $excluded,
        ];
        foreach (self::KINDS as $kind) {
            $totals[$kind . '_minutes'] = $minutes[$kind];
            $totals[$kind . '_hetd_milli'] = $this->calculator->hetdMilli($tenantId, $establishmentId, [$kind => $minutes[$kind]]);
        }

        return ['totals' => $totals, 'lines' => $lines];
    }

    /** Heure présentée aux conditions de payabilité : identifiant de la séance (retrouvée par la condition IPES). */
    public static function hour(string $tenantId, string $establishmentId, ?StaffInfo $staff, TaughtSessionInfo $session, ?string $level): PayableHour
    {
        return new PayableHour(
            $session->id,
            $tenantId,
            $establishmentId,
            $session->staffId ?? $staff->staffId ?? '',
            $session->personId,
            $staff->kind ?? StaffInfo::VACATAIRE,
            $session->heldOn,
            $session->minutes,
            $session->sessionKind,
            $session->staffAssignmentId,
            $session->elementId,
            $session->groupId,
            $level,
            $session->slotId,
            $session->content,
            $session->teacherSignedAt,
            null,
            $session->status,
        );
    }
}
