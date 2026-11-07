<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Tests\Support\Scheduling\SchedulingTestCase;

/** Vues par classe, enseignant et salle ; lecture cloisonnée ; annuaire pour les autres modules ; volumes horaires. */
final class TimetableViewTest extends SchedulingTestCase
{
    protected function setUp(): void
    {
        $this->bootScheduling();
        $this->slot();                                                                                                    // lundi 08-10, p-t1, c1, r1
        $this->slot(['teacher_person_id' => 'p-t2', 'room_id' => 'r2', 'structure_ids' => ['c2'], 'weekday' => 2, 'subject_label' => 'Histoire']);
        $this->slot(['teacher_person_id' => 'p-t1', 'room_id' => 'r2', 'structure_ids' => ['c1', 'c2'], 'weekday' => 6, 'start_time' => '08:00', 'end_time' => '12:00', 'subject_label' => 'Atelier']);
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $query, string $user = 'admin'): array
    {
        [$response, $json] = $this->api('GET', '/timetable?week=2026-10-14&' . $query, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $entries = [];
        foreach ($json['data']['days'] as $day) {
            foreach ($day['entries'] as $entry) {
                $entries[] = $entry + ['date' => $day['date']];
            }
        }

        return $entries;
    }

    public function testVueParClasseParEnseignantEtParSalle(): void
    {
        self::assertSame(['2026-10-12', '2026-10-17'], array_column($this->entries('structure_id=c1'), 'date'));
        self::assertSame(['2026-10-13', '2026-10-17'], array_column($this->entries('structure_id=c2'), 'date'));
        self::assertSame(['2026-10-12', '2026-10-17'], array_column($this->entries('teacher_person_id=p-t1'), 'date'));
        self::assertSame(['2026-10-13', '2026-10-17'], array_column($this->entries('room_id=r2'), 'date'));
        self::assertSame([], $this->entries('structure_id=c9'));
    }

    public function testLaSemaineVaDuLundiAuDimancheEtMarqueLesChangements(): void
    {
        $slot = $this->api('GET', '/timetable-slots?filter[weekday]=1')[1]['data'][0];
        $this->api('POST', '/timetable-changes', body: ['slot_id' => $slot['id'], 'on_date' => '2026-10-12', 'kind' => 'cancelled']);

        $json = $this->api('GET', '/timetable?week=2026-10-17&structure_id=c1')[1]['data'];
        self::assertSame('2026-10-12', $json['week_start']);
        self::assertSame('2026-10-18', $json['week_end']);
        self::assertCount(7, $json['days']);
        self::assertSame('cancelled', $json['days'][0]['entries'][0]['status']);
        self::assertSame('planned', $json['days'][5]['entries'][0]['status']);
        self::assertSame(['type' => 'structure_id', 'id' => 'c1'], $json['view']);
    }

    public function testParametresInvalides(): void
    {
        self::assertSame(422, $this->code('GET', '/timetable?structure_id=c1'));
        self::assertSame(422, $this->code('GET', '/timetable?week=2026-10-14'));
        self::assertSame(422, $this->code('GET', '/timetable?week=2026-10-14&structure_id=c1&room_id=r1'));
        self::assertSame(422, $this->code('GET', '/timetable?week=pas-une-date&room_id=r1'));
    }

    public function testUnEnseignantLitSonPropreEmploiDuTempsSeulement(): void
    {
        $this->account('u-t1', 'p-t1');

        self::assertCount(2, $this->entries('teacher_person_id=p-t1', 'u-t1'));
        self::assertSame([], $this->entries('teacher_person_id=p-t2', 'u-t1'));
        $slots = $this->api('GET', '/timetable-slots', 'u-t1')[1]['data'];
        self::assertSame(['p-t1'], array_values(array_unique(array_column($slots, 'teacher_person_id'))));
        $other = $this->api('GET', '/timetable-slots?filter[weekday]=2')[1]['data'][0];
        self::assertSame(404, $this->code('GET', '/timetable-slots/' . $other['id'], 'u-t1'));
        self::assertSame(403, $this->code('POST', '/timetable-slots', 'u-t1', body: $this->slotBody(['weekday' => 4])));
    }

    public function testUnApprenantEtSonGarantLisentLEmploiDuTempsDeLeursClasses(): void
    {
        $this->account('u-l1', 'p-l1');
        $this->account('u-g1', 'p-g1');
        $this->account('u-l2', 'p-l2');

        $learner = $this->entries('structure_id=c1', 'u-l1');
        self::assertSame(['2026-10-12', '2026-10-17'], array_column($learner, 'date'));
        self::assertSame(['2026-10-17'], array_column($this->entries('structure_id=c2', 'u-l1'), 'date'), 'une autre classe : seulement le cours mutualisé avec la sienne');
        self::assertSame(['2026-10-12', '2026-10-17'], array_column($this->entries('structure_id=c1', 'u-g1'), 'date'), 'garant : les classes de son enfant');
        self::assertSame(['2026-10-13', '2026-10-17'], array_column($this->entries('structure_id=c2', 'u-l2'), 'date'));
        self::assertCount(2, $this->api('GET', '/timetable-slots', 'u-l1')[1]['data']);
        self::assertSame(403, $this->code('GET', '/timetable/consumption?course_unit_id=u1&as_of=2026-10-30', 'u-l1'));
    }

    public function testUnApprenantNeLitQueLesChangementsDeSesClasses(): void
    {
        $slot = $this->api('GET', '/timetable-slots?filter[weekday]=1')[1]['data'][0];
        $other = $this->api('GET', '/timetable-slots?filter[weekday]=2')[1]['data'][0];
        $mine = $this->api('POST', '/timetable-changes', body: ['slot_id' => $slot['id'], 'on_date' => '2026-10-12', 'kind' => 'cancelled'])[1]['data'];
        $theirs = $this->api('POST', '/timetable-changes', body: ['slot_id' => $other['id'], 'on_date' => '2026-10-13', 'kind' => 'cancelled'])[1]['data'];
        $this->account('u-l1', 'p-l1');

        self::assertSame([$mine['id']], array_column($this->api('GET', '/timetable-changes', 'u-l1')[1]['data'], 'id'));
        self::assertSame(200, $this->code('GET', '/timetable-changes/' . $mine['id'], 'u-l1'));
        self::assertSame(404, $this->code('GET', '/timetable-changes/' . $theirs['id'], 'u-l1'));
    }

    public function testLAnnuaireExposeLesCoursMutualisesParStructure(): void
    {
        $directory = $this->kernel->container()->get(TimetableDirectory::class);
        $saturday = $directory->slotsOn('t1', 'e1', '2026-10-17');

        self::assertSame(['c1', 'c2'], array_map(static fn ($s) => $s->structureId, $saturday));
        self::assertSame($saturday[0]->id, $saturday[1]->id);
        self::assertCount(2, $directory->teacherSlotsOn('t1', 'p-t1', '2026-10-17'), 'un SlotInfo par structure pour le même créneau');
        self::assertSame([], $directory->slotsOn('t1', 'e1', '2026-10-18'));
        self::assertSame([], $directory->slotsOn('t1', 'e1', '2026-09-01'), 'avant la validité');
        $monday = (string) $this->api('GET', '/timetable-slots?filter[weekday]=1')[1]['data'][0]['id'];
        self::assertSame('Mathématiques', $directory->slot('t1', $monday)?->subjectLabel);
        self::assertNull($directory->slot('t2', $monday));
        self::assertNull($directory->slot('t2', $saturday[0]->id), 'jamais d\'un autre client');
    }

    public function testSuiviDesVolumesParElementConstitutif(): void
    {
        $this->slot(['course_unit_id' => 'ec1', 'weekday' => 3, 'start_time' => '08:00', 'end_time' => '10:00', 'valid_from' => '2026-10-07', 'valid_to' => '2026-10-28', 'structure_ids' => ['c3'], 'teacher_person_id' => 'p-t3', 'room_id' => 'r3']);
        // mercredis 07, 14, 21, 28 octobre ; le 14 est annulé, le 21 est férié
        $slot = $this->api('GET', '/timetable-slots?filter[course_unit_id]=ec1')[1]['data'][0];
        $this->api('POST', '/timetable-changes', body: ['slot_id' => $slot['id'], 'on_date' => '2026-10-14', 'kind' => 'cancelled']);
        $this->api('POST', '/calendar-events', body: ['kind' => 'holiday', 'title' => 'Férié', 'starts_on' => '2026-10-21']);

        $data = $this->api('GET', '/timetable/consumption?course_unit_id=ec1&as_of=2026-10-15&planned_minutes=600')[1]['data'];

        self::assertSame(120, $data['realized_minutes']);
        self::assertSame(120, $data['scheduled_upcoming_minutes']);
        self::assertSame(480, $data['remaining_minutes']);
        self::assertSame(360, $data['unscheduled_minutes']);
        self::assertNull($this->api('GET', '/timetable/consumption?course_unit_id=ec1&as_of=2026-10-15')[1]['data']['remaining_minutes']);
        self::assertSame(0, $this->api('GET', '/timetable/consumption?course_unit_id=inconnu&as_of=2026-10-15')[1]['data']['realized_minutes']);
        self::assertSame(422, $this->code('GET', '/timetable/consumption?course_unit_id=ec1'));
    }
}
