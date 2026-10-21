<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use DateTimeImmutable;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceAlerts;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceCalls;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Alertes d'assiduité (T638), lancées chaque jour par `public:absence-alerts` :
 *  - NOT-03 / EX-PRE-05 : un élève absent `public_schools.absence.repeated_days` jours (5 par défaut) dans la fenêtre
 *    `public_schools.absence.window` (`month` = mois civil, ou `rolling` sur `public_schools.absence.window_days` jours) : message à la famille et au
 *    directeur, une seule fois par période. Aucune sanction automatique (RG-PRE-3) ;
 *  - NOT-12 : une classe sans aucun appel depuis `public_schools.absence.no_call_days` jours ouvrés (3 par défaut) prévient le directeur ;
 *    à `public_schools.absence.no_call_inspection_days` jours, l'alerte monte au fil de l'inspection ;
 *  - NOT-02 (absence de l'enfant le matin) est envoyée par le socle Attendance à la validation de l'appel.
 * Jours ouvrés : jours de `public_schools.calendar.working_days` (1 = lundi … 7 = dimanche, lundi à vendredi par défaut), hors
 * `public_schools.calendar.closed_days` et couverts par une période de l'année scolaire. Un client suspendu ou fermé est sauté.
 */
final class AbsenceAlertService
{
    private const DEFAULT_REPEATED_DAYS = 5;
    private const DEFAULT_WINDOW_DAYS = 30;
    private const DEFAULT_NO_CALL_DAYS = 3;
    private const DEFAULT_INSPECTION_DAYS = 5;
    private const DEFAULT_SCAN_BACK_DAYS = 30;

    public function __construct(
        private readonly PdoAttendanceCalls $calls,
        private readonly PdoAttendanceAlerts $alerts,
        private readonly DailyPresence $presence,
        private readonly ClassRoster $classes,
        private readonly CurrentPeriod $periods,
        private readonly PublicMessages $messages,
        private readonly PersonDirectory $people,
        private readonly SettingsReader $settings,
        private readonly WriteGate $gate,
        private readonly CallClock $clock,
    ) {
    }

    /** @return array{alerts: int, messages: int, skipped_tenants: int} */
    public function run(?string $today = null): array
    {
        $today ??= $this->clock->today();
        $result = ['alerts' => 0, 'messages' => 0, 'skipped_tenants' => 0];
        $skipped = [];
        foreach ($this->calls->scopes() as [$tenantId, $establishmentId]) {
            if (!$this->gate->allowsWrites($tenantId)) {
                $skipped[$tenantId] = true;
                continue;
            }
            foreach ([$this->repeatedAbsences($tenantId, $establishmentId, $today), $this->missingCalls($tenantId, $establishmentId, $today)] as $part) {
                $result['alerts'] += $part['alerts'];
                $result['messages'] += $part['messages'];
            }
        }
        $result['skipped_tenants'] = count($skipped);

        return $result;
    }

    /** @return array{alerts: int, messages: int} */
    public function repeatedAbsences(string $tenantId, string $establishmentId, string $today): array
    {
        $out = ['alerts' => 0, 'messages' => 0];
        $threshold = $this->intSetting($tenantId, $establishmentId, 'public_schools.absence.repeated_days', self::DEFAULT_REPEATED_DAYS);
        $rolling = $this->settings->value($tenantId, $establishmentId, 'public_schools.absence.window', 'month') === 'rolling';
        $now = new DateTimeImmutable($today);
        $from = $rolling
            ? $now->modify(sprintf('-%d days', $this->intSetting($tenantId, $establishmentId, 'public_schools.absence.window_days', self::DEFAULT_WINDOW_DAYS) - 1))
            : $now->modify('first day of this month');

        /** @var array<string, list<string>> $daysByPupil */
        $daysByPupil = [];
        for ($day = $from; $day <= $now; $day = $day->modify('+1 day')) {
            foreach ($this->presence->absentOn($tenantId, $establishmentId, $day->format('Y-m-d')) as $pupilId) {
                $daysByPupil[$pupilId][] = $day->format('Y-m-d');
            }
        }
        $labels = null;
        foreach ($daysByPupil as $pupilId => $days) {
            if (count($days) < $threshold) {
                continue;
            }
            $key = $rolling ? $days[$threshold - 1] : $now->format('Y-m');
            if (!$this->alerts->raise($tenantId, $establishmentId, PdoAttendanceAlerts::REPEATED_ABSENCE, $pupilId, $key, PdoAttendanceAlerts::DIRECTOR, $today, $this->clock->now())) {
                continue;
            }
            $labels ??= $this->classLabels($tenantId, $establishmentId, $today);
            $variables = ['details' => (string) count($days), 'period_label' => $key];
            $reached = $this->messages->notifyGuardians($tenantId, $establishmentId, (string) $pupilId, 'pub.not03.parent', $variables);
            $director = $this->messages->notifyDirector($tenantId, $establishmentId, 'pub.not03.director', $variables + ['class_name' => $labels[$pupilId] ?? '', 'child_name' => $this->people->find($tenantId, (string) $pupilId)?->fullName() ?? '']);
            $this->alerts->setRecipients($tenantId, $establishmentId, PdoAttendanceAlerts::REPEATED_ABSENCE, (string) $pupilId, $key, PdoAttendanceAlerts::DIRECTOR, $reached + ($director ? 1 : 0));
            ++$out['alerts'];
            $out['messages'] += $reached + ($director ? 1 : 0);
        }

        return $out;
    }

    /** @return array{alerts: int, messages: int} */
    public function missingCalls(string $tenantId, string $establishmentId, string $today): array
    {
        $out = ['alerts' => 0, 'messages' => 0];
        $period = $this->periods->at($tenantId, $establishmentId, $today);
        if ($period === null) {
            return $out;
        }
        $limit = $this->intSetting($tenantId, $establishmentId, 'public_schools.absence.no_call_days', self::DEFAULT_NO_CALL_DAYS);
        $inspection = max($limit, $this->intSetting($tenantId, $establishmentId, 'public_schools.absence.no_call_inspection_days', self::DEFAULT_INSPECTION_DAYS));
        $back = $this->intSetting($tenantId, $establishmentId, 'public_schools.absence.scan_back_days', self::DEFAULT_SCAN_BACK_DAYS);
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day');
        $earliest = $yesterday->modify(sprintf('-%d days', $back - 1));
        $workingDays = $this->workingDays($tenantId, $establishmentId, $earliest, $yesterday);
        if (count($workingDays) < $limit) {
            return $out;
        }

        foreach ($this->classes->classesOf($tenantId, $establishmentId, $period->academicYearId) as $class) {
            if ($this->classes->headcount($tenantId, $establishmentId, $class->structureId, $today) === 0) {
                continue;
            }
            $withCall = $this->calls->daysWithCall($tenantId, $establishmentId, $class->structureId, $earliest->format('Y-m-d'), $yesterday->format('Y-m-d'));
            $streak = [];
            foreach (array_reverse($workingDays) as $day) {
                if (isset($withCall[$day])) {
                    break;
                }
                $streak[] = $day;
            }
            if (count($streak) < $limit) {
                continue;
            }
            $key = count($streak) === count($workingDays) ? 'never' : (string) end($streak);
            $out = $this->raiseMissing($tenantId, $establishmentId, $class, $key, count($streak), $streak[0], count($streak) >= $inspection, $today, $out);
        }

        return $out;
    }

    /**
     * @param array{alerts: int, messages: int} $out
     * @return array{alerts: int, messages: int}
     */
    private function raiseMissing(string $tenantId, string $establishmentId, ClassInfo $class, string $key, int $days, string $lastDay, bool $escalate, string $today, array $out): array
    {
        $now = $this->clock->now();
        if ($this->alerts->raise($tenantId, $establishmentId, PdoAttendanceAlerts::NO_CALL, $class->structureId, $key, PdoAttendanceAlerts::DIRECTOR, $today, $now)) {
            $sent = $this->messages->notifyDirector($tenantId, $establishmentId, 'pub.not12.director', ['class_name' => $class->label, 'details' => (string) $days, 'date' => $lastDay]);
            $this->alerts->setRecipients($tenantId, $establishmentId, PdoAttendanceAlerts::NO_CALL, $class->structureId, $key, PdoAttendanceAlerts::DIRECTOR, $sent ? 1 : 0);
            ++$out['alerts'];
            $out['messages'] += $sent ? 1 : 0;
        }
        if ($escalate && $this->alerts->raise($tenantId, $establishmentId, PdoAttendanceAlerts::NO_CALL, $class->structureId, $key, PdoAttendanceAlerts::INSPECTION, $today, $now)) {
            ++$out['alerts'];
        }

        return $out;
    }

    /** @return list<string> jours ouvrés AAAA-MM-JJ de la période, du plus ancien au plus récent */
    private function workingDays(string $tenantId, string $establishmentId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $weekdays = $this->settings->value($tenantId, $establishmentId, 'public_schools.calendar.working_days', [1, 2, 3, 4, 5]);
        $weekdays = is_array($weekdays) ? array_map('intval', $weekdays) : [1, 2, 3, 4, 5];
        $closed = $this->settings->value($tenantId, $establishmentId, 'public_schools.calendar.closed_days', []);
        $closed = is_array($closed) ? array_map('strval', $closed) : [];
        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $iso = $day->format('Y-m-d');
            if (in_array((int) $day->format('N'), $weekdays, true) && !in_array($iso, $closed, true) && $this->periods->at($tenantId, $establishmentId, $iso) !== null) {
                $days[] = $iso;
            }
        }

        return $days;
    }

    /** @return array<string, string> libellé de la classe de chaque élève */
    private function classLabels(string $tenantId, string $establishmentId, string $today): array
    {
        $period = $this->periods->at($tenantId, $establishmentId, $today);
        $labels = [];
        if ($period === null) {
            return $labels;
        }
        foreach ($this->classes->classesOf($tenantId, $establishmentId, $period->academicYearId) as $class) {
            foreach ($this->classes->pupilIdsIn($tenantId, $establishmentId, $class->structureId, $today) as $pupilId) {
                $labels[$pupilId] = $class->label;
            }
        }

        return $labels;
    }

    private function intSetting(string $tenantId, string $establishmentId, string $key, int $default): int
    {
        $value = $this->settings->value($tenantId, $establishmentId, $key, $default);

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : $default;
    }
}
