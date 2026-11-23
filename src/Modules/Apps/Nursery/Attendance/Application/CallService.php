<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\GateJournal;
use Scholaris\Modules\Apps\Nursery\Pickup\Contracts\ArrivalJournal;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceRecorder;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Appel du matin et arrivées (EX-MAT-23, 24) : l'aide fait l'appel de SA section par demi-journée (contrat AttendanceRecorder du
 * socle : séance idempotente, présents par défaut, absences signalées aux parents par le socle) et chaque enfant présent reçoit une
 * ARRIVÉE dans le journal de sortie, base de la veille de non-récupération et du registre du jour.
 */
final class CallService
{
    public const DAY_PARTS = ['morning', 'afternoon', 'full_day'];

    public function __construct(
        private readonly AttendanceRecorder $recorder,
        private readonly DailyPresence $presence,
        private readonly ArrivalJournal $arrivals,
        private readonly GateJournal $journal,
        private readonly SectionService $sections,
        private readonly ChildDirectory $children,
        private readonly PersonDirectory $people,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data day_part, client_uuid, date?, exceptions?: list<array{child_id, status, arrived_at?, note?}>, validate?
     * @return array<string, mixed>
     */
    public function call(Principal $principal, Perimeter $perimeter, string $sectionId, array $data): array
    {
        if (!$this->sections->exists($perimeter->tenantId, $perimeter->establishmentId, $sectionId)) {
            throw HttpException::notFound('Section introuvable.');
        }
        $this->access->assertWritable($principal, $perimeter, $sectionId, 'section', $sectionId);
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $day = (string) ($data['date'] ?? $this->clock->today());
        $roster = $this->sections->childIds($tenantId, $sectionId);
        $marks = [];
        foreach ((array) ($data['exceptions'] ?? []) as $exception) {
            $childId = (string) $exception['child_id'];
            if (!in_array($childId, $roster, true)) {
                throw HttpException::validation(['exceptions' => ['Un enfant de la liste n’est pas inscrit dans cette section.']]);
            }
            $marks[$childId] = new AttendanceMark($childId, (string) $exception['status'], isset($exception['arrived_at']) ? (string) $exception['arrived_at'] : null, null, isset($exception['note']) ? (string) $exception['note'] : null);
        }
        $session = $this->recorder->openDayPart($tenantId, $principal, $establishmentId, $sectionId, (string) $data['day_part'], $day, (string) $data['client_uuid']);
        $session = $this->recorder->record($tenantId, $principal, $establishmentId, $session->id, $sectionId, array_values($marks));
        if (($data['validate'] ?? false) === true) {
            $session = $this->recorder->validate($tenantId, $principal, $establishmentId, $session->id);
        }
        $present = 0;
        $late = 0;
        $absent = 0;
        foreach ($roster as $childId) {
            $mark = $marks[$childId] ?? null;
            if ($mark !== null && $mark->status === AttendanceMark::ABSENT) {
                ++$absent;
                continue;
            }
            $mark !== null && $mark->status === AttendanceMark::LATE ? ++$late : ++$present;
            $this->arrivals->recordArrival($tenantId, $establishmentId, $childId, $sectionId, $day, $mark->arrivedAt ?? $this->clock->now(), $principal->userId, 'online', null);
        }
        $this->access->audit($principal, 'nursery.call.recorded', 'attendance_session', $session->id, null, ['section_id' => $sectionId, 'day_part' => $data['day_part'], 'present' => $present, 'late' => $late, 'absent' => $absent]);

        return ['session_id' => $session->id, 'status' => $session->status, 'date' => $day, 'day_part' => (string) $data['day_part'], 'present' => $present, 'late' => $late, 'absent' => $absent];
    }

    /**
     * Arrivée d'un enfant en retard ou hors appel (une seule arrivée par enfant et par jour).
     *
     * @return array<string, mixed>
     */
    public function arrival(Principal $principal, Perimeter $perimeter, string $childId, ?string $arrivedAt, ?string $clientUuid): array
    {
        $section = $this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, $childId) ?? throw HttpException::notFound('Enfant introuvable.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $childId);
        $day = $this->clock->today();
        $added = $this->arrivals->recordArrival($perimeter->tenantId, $perimeter->establishmentId, $childId, $section, $day, $arrivedAt ?? $this->clock->now(), $principal->userId, 'online', $clientUuid);
        $this->access->audit($principal, 'nursery.arrival.recorded', 'child', $childId, null, ['day' => $day, 'added' => $added]);

        return ['child_id' => $childId, 'school_day' => $day, 'recorded' => $added];
    }

    /**
     * Liste d'appel de la section : chaque enfant avec son statut du jour (attendu, présent, absent) et son heure d'arrivée.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function roll(Perimeter $perimeter, string $sectionId, string $day, Paging $paging): array
    {
        $this->sections->get($perimeter, $sectionId);
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $absent = array_flip($this->presence->absentOn($tenantId, $establishmentId, $day, $sectionId));
        $arrivals = [];
        foreach ($this->journal->onDay($tenantId, $establishmentId, $day) as $event) {
            if ($event['event_type'] === GateJournal::ARRIVAL) {
                $arrivals[(string) $event['learner_id']] = (string) $event['occurred_at'];
            }
        }
        $rows = [];
        $people = $this->people->findMany($tenantId, $this->sections->childIds($tenantId, $sectionId));
        usort($people, static fn ($a, $b): int => [mb_strtolower($a->lastName), mb_strtolower($a->firstName)] <=> [mb_strtolower($b->lastName), mb_strtolower($b->firstName)]);
        foreach ($people as $person) {
            $rows[] = [
                'child_id' => $person->id, 'full_name' => $person->fullName(),
                'status' => isset($arrivals[$person->id]) ? 'present' : (isset($absent[$person->id]) ? 'absent' : 'expected'),
                'arrived_at' => $arrivals[$person->id] ?? null,
            ];
        }

        return $paging->slice($rows);
    }
}
