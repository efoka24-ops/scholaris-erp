<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\NationalExamAttendance;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\SittingEligibility;
use Scholaris\Modules\Apps\Higher\Attendance\Domain\AssiduityRule;
use Scholaris\Modules\Apps\Higher\Attendance\Infrastructure\AttendanceStore;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Attendance\Contracts\UnitAttendanceSummaryProvider;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Assiduité par EC (EX-PRE-03, RG-PRE-1) calculée sur l'appel du socle (D19 ; base `attendance.base`, séances tenues par défaut),
 * alertes NOT-07 à 25 %, non-autorisés à composer au-delà de 30 % et leur transmission au service des examens (EX-PRE-05), levée
 * tracée par dérogation ; second indicateur distinct : présence ≥ 70 % pour les examens nationaux BTS/HND (T426).
 */
final class AssiduityService implements SittingEligibility, NationalExamAttendance
{
    public const ALERTS = 'hi_attendance_alerts';
    public const LIFTS = 'hi_barred_lifts';
    public const LISTS = 'hi_barred_lists';

    public function __construct(
        private readonly AttendanceStore $store,
        private readonly AttendanceAccess $access,
        private readonly Placement $placement,
        private readonly StudentDirectory $students,
        private readonly CurriculumDirectory $curricula,
        private readonly UnitAttendanceSummaryProvider $units,
        private readonly AttendanceSummaryProvider $summaries,
        private readonly SettingsReader $settings,
        private readonly HigherNotifier $notifier,
        private readonly AttendanceClock $clock,
    ) {
    }

    /**
     * Assiduité des étudiants d'un groupe pour un EC.
     *
     * @return array{element_id: string, group_id: string, max_percent: float, alert_percent: float, students: list<array<string, mixed>>}
     */
    public function forElement(Principal $principal, string $establishmentId, string $elementId, string $groupId, string $academicYearId): array
    {
        $tenantId = $principal->tenantId;
        $place = $this->placement->group($tenantId, $establishmentId, $groupId);
        $this->access->assertReadable($this->access->require($principal, $establishmentId, array_merge(AttendancePermissions::READ, AttendancePermissions::MANAGE)), $place);

        return $this->compute($tenantId, $establishmentId, $elementId, $groupId, $academicYearId);
    }

    /**
     * Assiduité d'un étudiant pour chaque EC de sa maquette (portail étudiant, garant avec consentement, scolarité).
     *
     * @return list<array<string, mixed>>
     */
    public function forStudent(Principal $principal, string $establishmentId, string $personId): array
    {
        $tenantId = $principal->tenantId;
        [$enrolment, $place] = $this->placement->enrolment($tenantId, $establishmentId, $personId);
        if ($this->access->personOf($principal) !== $personId) {
            $this->access->assertReadable($this->access->require($principal, $establishmentId, array_merge(AttendancePermissions::READ, AttendancePermissions::MANAGE)), $place);
        }
        [$max, $alert] = $this->thresholds($tenantId, $establishmentId);
        $rows = [];
        foreach ($enrolment->curriculumId === null ? [] : $this->curricula->units($tenantId, $establishmentId, $enrolment->curriculumId) as $unit) {
            foreach ($unit->elements as $element) {
                $summary = $this->units->forLearnerByUnit($tenantId, $establishmentId, $personId, $element['id']);
                $rows[] = ['element_id' => $element['id'], 'element_code' => $element['code'], 'element_title' => $element['title'], 'unit_code' => $unit->code]
                    + AssiduityRule::forElement($summary->sessions, $summary->unjustifiedAbsences, $max, $alert, $this->isLifted($tenantId, $establishmentId, $personId, $element['id']))
                    + ['absences' => $summary->absences, 'lates' => $summary->lates];
            }
        }

        return $rows;
    }

    /**
     * Alertes NOT-07 (25 %) aux étudiants d'un groupe pour un EC, une seule fois par étudiant et EC (garants avec consentement).
     *
     * @return array{alerted: list<string>, already: list<string>}
     */
    public function sendAlerts(Principal $principal, string $establishmentId, string $elementId, string $groupId, string $academicYearId, string $elementTitle): array
    {
        $tenantId = $principal->tenantId;
        $place = $this->placement->group($tenantId, $establishmentId, $groupId);
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, AttendancePermissions::MANAGE), $place, 'hi_group', $groupId);
        $alerted = $already = [];
        foreach ($this->compute($tenantId, $establishmentId, $elementId, $groupId, $academicYearId)['students'] as $row) {
            if ($row['status'] !== AssiduityRule::ALERT) {
                continue;
            }
            $personId = (string) $row['person_id'];
            if ($this->store->count(self::ALERTS, $tenantId, $establishmentId, ['person_id' => $personId, 'element_id' => $elementId, 'alert_level' => AssiduityRule::ALERT]) > 0) {
                $already[] = $personId;
                continue;
            }
            $this->notifier->notify($tenantId, $establishmentId, 'NOT-07', $personId, [
                'subject' => $elementTitle, 'details' => sprintf('%d absences non justifiées sur %d séances, %s %%', $row['unjustified'], $row['sessions'], number_format((float) $row['rate_percent'], 1, ',', '')),
            ], 'not07:' . $elementId . ':' . $personId);
            $this->store->insert(self::ALERTS, $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'person_id' => $personId, 'element_id' => $elementId, 'group_id' => $groupId, 'alert_level' => AssiduityRule::ALERT,
                'rate_permille' => (int) round((float) $row['rate_percent'] * 10), 'sent_at' => $this->clock->now(),
            ]);
            $alerted[] = $personId;
        }
        $this->access->audit($tenantId, $principal->userId, 'higher.attendance.alerts_sent', 'hi_group', $groupId, null, ['element_id' => $elementId, 'alerted' => $alerted]);

        return ['alerted' => $alerted, 'already' => $already];
    }

    /**
     * Transmission de la liste des non-autorisés à composer d'un EC au service des examens (EX-PRE-05).
     *
     * @return array<string, mixed>
     */
    public function transmit(Principal $principal, string $establishmentId, string $elementId, string $groupId, string $academicYearId): array
    {
        $tenantId = $principal->tenantId;
        $place = $this->placement->group($tenantId, $establishmentId, $groupId);
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, AttendancePermissions::MANAGE), $place, 'hi_group', $groupId);
        $result = $this->compute($tenantId, $establishmentId, $elementId, $groupId, $academicYearId);
        $entries = array_values(array_filter($result['students'], static fn (array $r): bool => $r['status'] === AssiduityRule::BARRED));
        $row = $place + [
            'id' => (string) new Ulid(), 'academic_year_id' => $academicYearId, 'element_id' => $elementId, 'threshold_percent' => (int) $result['max_percent'],
            'entries' => json_encode(array_map(static fn (array $r): array => array_intersect_key($r, ['person_id' => 1, 'matricule' => 1, 'sessions' => 1, 'unjustified' => 1, 'rate_percent' => 1]), $entries), JSON_THROW_ON_ERROR),
            'barred_count' => count($entries), 'transmitted_by' => $principal->userId, 'transmitted_at' => $this->clock->now(),
        ];
        $this->store->insert(self::LISTS, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.attendance.barred_list_transmitted', 'hi_barred_list', $row['id'], null, ['element_id' => $elementId, 'group_id' => $groupId, 'barred_count' => count($entries)]);

        return $this->store->findOrFail(self::LISTS, $tenantId, $establishmentId, $row['id']);
    }

    /**
     * Accusé de réception de la liste par le service des examens.
     *
     * @return array<string, mixed>
     */
    public function receive(Principal $principal, string $establishmentId, string $listId): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail(self::LISTS, $tenantId, $establishmentId, $listId, 'Liste introuvable.');
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, AttendancePermissions::EXAMS), $row, 'hi_barred_list', $listId);
        if ($row['received_at'] === null) {
            $this->store->update(self::LISTS, $tenantId, $establishmentId, ['received_by' => $principal->userId, 'received_at' => $this->clock->now()], ['id' => $listId]);
        }

        return $this->store->findOrFail(self::LISTS, $tenantId, $establishmentId, $listId);
    }

    /**
     * Levée tracée de l'interdiction de composer (dérogation du directeur d'école).
     *
     * @return array<string, mixed>
     */
    public function lift(Principal $principal, string $establishmentId, string $personId, string $elementId, string $groupId, string $reason, ?string $documentRef): array
    {
        $tenantId = $principal->tenantId;
        $place = $this->placement->group($tenantId, $establishmentId, $groupId);
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, AttendancePermissions::LIFT), $place, 'hi_group', $groupId);
        if (!$this->isBarred($tenantId, $establishmentId, $personId, $elementId)) {
            throw HttpException::conflict('Cet étudiant n\'est pas sous le coup d\'une interdiction de composer cet EC.');
        }
        $row = $place + [
            'id' => (string) new Ulid(), 'person_id' => $personId, 'element_id' => $elementId, 'reason' => $reason, 'document_ref' => $documentRef,
            'lifted_by' => $principal->userId, 'lifted_at' => $this->clock->now(),
        ];
        $this->store->insert(self::LIFTS, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.attendance.barred_lifted', 'hi_barred_lift', $row['id'], null, $row);

        return $row;
    }

    public function isBarred(string $tenantId, string $establishmentId, string $personId, string $elementId): bool
    {
        [$max, $alert] = $this->thresholds($tenantId, $establishmentId);
        $summary = $this->units->forLearnerByUnit($tenantId, $establishmentId, $personId, $elementId);

        return AssiduityRule::forElement($summary->sessions, $summary->unjustifiedAbsences, $max, $alert, $this->isLifted($tenantId, $establishmentId, $personId, $elementId))['status'] === AssiduityRule::BARRED;
    }

    public function barredAmong(string $tenantId, string $establishmentId, string $elementId, array $personIds): array
    {
        return array_values(array_filter($personIds, fn (string $personId): bool => $this->isBarred($tenantId, $establishmentId, $personId, $elementId)));
    }

    public function presenceOf(string $tenantId, string $establishmentId, string $personId, string $from, string $to): array
    {
        $summary = $this->summaries->forLearner($tenantId, $establishmentId, $personId, $from, $to);
        $min = (float) $this->settings->value($tenantId, $establishmentId, AttendancePermissions::NATIONAL_MIN_PRESENCE, 70);
        $result = AssiduityRule::presence($summary->sessions, $summary->unjustifiedAbsences, $min);

        return ['sessions' => $result['sessions'], 'unjustified' => $result['unjustified'], 'presence_percent' => $result['presence_percent'], 'threshold_percent' => $result['threshold_percent'], 'eligible' => $result['eligible']];
    }

    /**
     * Présence de l'étudiant (indicateur des examens nationaux), lecture contrôlée.
     *
     * @return array<string, mixed>
     */
    public function presenceFor(Principal $principal, string $establishmentId, string $personId, string $from, string $to): array
    {
        [, $place] = $this->placement->enrolment($principal->tenantId, $establishmentId, $personId);
        if ($this->access->personOf($principal) !== $personId) {
            $this->access->assertReadable($this->access->require($principal, $establishmentId, array_merge(AttendancePermissions::READ, AttendancePermissions::MANAGE)), $place);
        }

        return ['person_id' => $personId, 'from' => $from, 'to' => $to] + $this->presenceOf($principal->tenantId, $establishmentId, $personId, $from, $to);
    }

    /** @return array{element_id: string, group_id: string, max_percent: float, alert_percent: float, students: list<array<string, mixed>>} */
    private function compute(string $tenantId, string $establishmentId, string $elementId, string $groupId, string $academicYearId): array
    {
        [$max, $alert] = $this->thresholds($tenantId, $establishmentId);
        $students = [];
        foreach ($this->students->enrolledIn($tenantId, $establishmentId, $groupId, $academicYearId) as $enrolment) {
            $summary = $this->units->forLearnerByUnit($tenantId, $establishmentId, $enrolment->personId, $elementId);
            $students[] = ['person_id' => $enrolment->personId, 'matricule' => $enrolment->matricule, 'enrolment_id' => $enrolment->id]
                + AssiduityRule::forElement($summary->sessions, $summary->unjustifiedAbsences, $max, $alert, $this->isLifted($tenantId, $establishmentId, $enrolment->personId, $elementId))
                + ['absences' => $summary->absences, 'lates' => $summary->lates];
        }

        return ['element_id' => $elementId, 'group_id' => $groupId, 'max_percent' => $max, 'alert_percent' => $alert, 'students' => $students];
    }

    private function isLifted(string $tenantId, string $establishmentId, string $personId, string $elementId): bool
    {
        return $this->store->count(self::LIFTS, $tenantId, $establishmentId, ['person_id' => $personId, 'element_id' => $elementId]) > 0;
    }

    /** @return array{0: float, 1: float} */
    private function thresholds(string $tenantId, string $establishmentId): array
    {
        $max = $this->settings->value($tenantId, $establishmentId, AttendancePermissions::MAX_UNJUSTIFIED, 30);
        $alert = $this->settings->value($tenantId, $establishmentId, AttendancePermissions::ALERT_UNJUSTIFIED, 25);

        return [is_numeric($max) ? (float) $max : 30.0, is_numeric($alert) ? (float) $alert : 25.0];
    }
}
