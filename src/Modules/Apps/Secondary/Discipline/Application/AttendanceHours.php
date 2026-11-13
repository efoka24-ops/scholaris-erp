<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Discipline\Domain\DisciplineDefaults;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;

/**
 * Cumul en heures de l'appel du socle (D1, D13 : appel par heure de cours, séances validées) : absences, absences justifiées
 * (justificatif accepté au socle) et non justifiées, retards. Une séance d'appel compte DisciplineDefaults::HOURS_PER_SESSION.
 * Période : bornes données, sinon le trimestre (à défaut la période) qui couvre le jour.
 */
final class AttendanceHours
{
    private const KINDS = ['trimester' => 'Trimestre', 'sequence' => 'Séquence', 'semester' => 'Semestre'];

    public function __construct(
        private readonly AttendanceTotals $totals,
        private readonly CurrentPeriod $periods,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * Libellé par défaut en français (« Trimestre 1 », données enregistrées et SMS) ; `kind` et `ordinal` permettent à l'écran de le
     * traduire (FIX-SEC : plus de « trimester 1 »).
     *
     * @return array{from: string, to: string, label: string, kind?: string, ordinal?: int}
     */
    public function period(string $tenantId, string $establishmentId, ?string $from, ?string $to, ?string $label = null): array
    {
        if ($from !== null && $to !== null) {
            if ($from > $to) {
                throw HttpException::validation(['to' => ['La fin de la période précède son début.']]);
            }

            return ['from' => $from, 'to' => $to, 'label' => $label ?? ($from . ' – ' . $to)];
        }
        if ($from !== null || $to !== null) {
            throw HttpException::validation([($from === null ? 'from' : 'to') => ['Donnez les deux bornes de la période, ou aucune.']]);
        }
        $chosen = null;
        foreach ($this->periods->allAt($tenantId, $establishmentId, $this->clock->today()) as $period) {
            if ($period->kind === 'trimester') {
                $chosen = $period;
            }
        }
        $chosen ??= $this->periods->at($tenantId, $establishmentId, $this->clock->today());
        if ($chosen === null) {
            throw HttpException::validation(['from' => ['Aucune période ne couvre ce jour : donnez les bornes de la période.']]);
        }

        if ($label !== null) {
            return ['from' => $chosen->startsOn, 'to' => $chosen->endsOn, 'label' => $label];
        }

        return ['from' => $chosen->startsOn, 'to' => $chosen->endsOn, 'label' => self::label($chosen->kind, $chosen->ordinal), 'kind' => $chosen->kind, 'ordinal' => $chosen->ordinal];
    }

    /** Libellé français d'une période du socle : « Trimestre 1 », « Séquence 2 », « Semestre 1 », sinon « Période 3 ». */
    public static function label(string $kind, int $ordinal): string
    {
        return (self::KINDS[$kind] ?? 'Période') . ' ' . $ordinal;
    }

    /** @return array<string, AttendanceSummary> par élève ayant au moins une séance validée */
    public function byLearner(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        return $this->totals->byLearner($tenantId, $establishmentId, $from, $to);
    }

    /** @return array{sessions: int, absent_hours: float, unjustified_hours: float, justified_hours: float, lates: int} */
    public static function hours(?AttendanceSummary $summary): array
    {
        if ($summary === null) {
            return ['sessions' => 0, 'absent_hours' => 0.0, 'unjustified_hours' => 0.0, 'justified_hours' => 0.0, 'lates' => 0];
        }
        $unit = DisciplineDefaults::HOURS_PER_SESSION;

        return [
            'sessions' => $summary->sessions,
            'absent_hours' => $summary->absences * $unit,
            'unjustified_hours' => $summary->unjustifiedAbsences * $unit,
            'justified_hours' => max(0, $summary->absences - $summary->unjustifiedAbsences) * $unit,
            'lates' => $summary->lates,
        ];
    }
}
