<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Scheduling\Contracts\CourseUnitOccurrences;
use Scholaris\Modules\Socle\Scheduling\Contracts\OccurrenceInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotDraft;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableConflict;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableWriter;
use Tests\Support\Scheduling\SchedulingTestCase;

/** Extensions IPES du module Scheduling : nature de séance (D12), écriture par contrat (D11), occurrences d'un EC (D13). */
final class IpesExtensionsTest extends SchedulingTestCase
{
    protected function setUp(): void
    {
        $this->bootScheduling();
    }

    private function writer(): TimetableWriter
    {
        return $this->kernel->container()->get(TimetableWriter::class);
    }

    private function occurrences(): CourseUnitOccurrences
    {
        return $this->kernel->container()->get(CourseUnitOccurrences::class);
    }

    /** Mercredi 08:00-10:00 du 7 au 28 octobre 2026 pour l'EC ec1. */
    private function draft(string $kind = 'cm', string $start = '08:00', string $end = '10:00', string $teacher = 'p-t3', string $room = 'r3'): SlotDraft
    {
        return new SlotDraft('Comptabilité générale', $teacher, ['g1'], 3, $start, $end, '2026-10-07', '2026-10-28', $room, 'ec1', $kind);
    }

    public function testUnCreneauSansNatureGardeLeComportementHistorique(): void
    {
        $slot = $this->slot();

        self::assertNull($slot['session_kind']);
        self::assertNull($this->kernel->container()->get(TimetableDirectory::class)->slot('t1', (string) $slot['id'])?->sessionKind);
    }

    public function testLaNatureDeSeanceEstSaisieFiltreeEtExposee(): void
    {
        $slot = $this->slot(['session_kind' => 'td', 'course_unit_id' => 'ec9']);
        self::assertSame('td', $slot['session_kind']);
        self::assertSame(422, $this->code('POST', '/timetable-slots', body: $this->slotBody(['session_kind' => 'atelier', 'weekday' => 2])));

        $updated = $this->api('PATCH', '/timetable-slots/' . $slot['id'], body: ['session_kind' => 'tp'])[1]['data'];
        self::assertSame('tp', $updated['session_kind']);
        self::assertCount(1, $this->api('GET', '/timetable-slots?filter[session_kind]=tp')[1]['data']);
        self::assertCount(0, $this->api('GET', '/timetable-slots?filter[session_kind]=cm')[1]['data']);

        $info = $this->kernel->container()->get(TimetableDirectory::class)->slot('t1', (string) $slot['id']);
        self::assertSame(['ec9', 'tp'], [$info?->courseUnitId, $info?->sessionKind]);
        $held = $this->kernel->container()->get(TimetableDirectory::class)->slotsOn('t1', 'e1', '2026-10-12');
        self::assertSame(['ec9', 'tp'], [$held[0]->courseUnitId, $held[0]->sessionKind], 'les occurrences portent l\'EC et la nature');
    }

    public function testLeContratDEcriturePoseModifieEtSupprimeUnCreneauAvecDetectionDesConflits(): void
    {
        $writer = $this->writer();
        $id = $writer->create('t1', 'e1', $this->draft(), 'admin');

        $row = $this->api('GET', '/timetable-slots/' . $id)[1]['data'];
        self::assertSame(['ec1', 'cm', ['g1'], 'admin'], [$row['course_unit_id'], $row['session_kind'], $row['structure_ids'], 'admin']);

        // même enseignant, même heure : conflit (enseignant, salle et groupe)
        self::assertNotSame([], $writer->check('t1', 'e1', $this->draft('td', '09:00', '11:00')));
        self::assertSame([], $writer->check('t1', 'e1', $this->draft('td', '10:00', '12:00')));
        self::assertSame([], $writer->check('t1', 'e1', $this->draft('td', '09:00', '11:00'), $id), 'le créneau modifié est ignoré');
        try {
            $writer->create('t1', 'e1', $this->draft('td', '09:00', '11:00'), 'admin');
            self::fail('Conflit attendu.');
        } catch (TimetableConflict $e) {
            self::assertContains('teacher', array_column($e->conflicts, 'kind'));
        }

        $writer->update('t1', 'e1', $id, ['session_kind' => 'tp', 'start_time' => '14:00', 'end_time' => '17:00']);
        $row = $this->api('GET', '/timetable-slots/' . $id)[1]['data'];
        self::assertSame(['tp', '14:00', '17:00'], [$row['session_kind'], $row['start_time'], $row['end_time']]);

        try {
            $writer->update('t1', 'e1', $id, ['session_kind' => 'atelier']);
            self::fail('Validation attendue.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        try {
            $writer->create('t1', 'e1', $this->draft('cm', '10:00', '09:00'), 'admin');
            self::fail('Validation attendue.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }

        // autre client : invisible
        try {
            $writer->delete('t2', 'e1', $id);
            self::fail('404 attendu.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
        $writer->delete('t1', 'e1', $id);
        self::assertSame(404, $this->code('GET', '/timetable-slots/' . $id));
    }

    public function testOccurrencesTenuesEtConsommationParNatureDUnElementConstitutif(): void
    {
        $writer = $this->writer();
        $cm = $writer->create('t1', 'e1', $this->draft('cm'), 'admin');
        $writer->create('t1', 'e1', $this->draft('td', '10:00', '12:00'), 'admin');
        // mercredis 7, 14, 21, 28 octobre : le CM du 14 est annulé, le 21 est férié
        $this->api('POST', '/timetable-changes', body: ['slot_id' => $cm, 'on_date' => '2026-10-14', 'kind' => 'cancelled']);
        $this->api('POST', '/calendar-events', body: ['kind' => 'holiday', 'title' => 'Férié', 'starts_on' => '2026-10-21']);

        $occurrences = $this->occurrences();
        $held = $occurrences->heldOf('t1', 'e1', 'ec1', '2026-10-01', '2026-10-31');
        self::assertSame(
            [['2026-10-07', 'cm'], ['2026-10-07', 'td'], ['2026-10-14', 'td'], ['2026-10-28', 'cm'], ['2026-10-28', 'td']],
            array_map(static fn (OccurrenceInfo $o): array => [$o->date, $o->sessionKind], $held),
        );
        self::assertSame(120, $held[0]->minutes);
        self::assertTrue($held[0]->isHeld());

        $scheduled = $occurrences->scheduledOf('t1', 'e1', 'ec1', '2026-10-01', '2026-10-31');
        self::assertCount(6, $scheduled, 'l\'annulé compte comme prévu, le férié n\'est pas une occurrence');
        self::assertContains(OccurrenceInfo::CANCELLED, array_map(static fn (OccurrenceInfo $o): string => $o->status, $scheduled));

        self::assertSame(['cm' => 240, 'td' => 360], $occurrences->heldMinutesByKind('t1', 'e1', 'ec1', '2026-10-01', '2026-10-31'));
        self::assertSame(['cm' => 120, 'td' => 120], $occurrences->heldMinutesByKind('t1', 'e1', 'ec1', '2026-10-01', '2026-10-07'));
        self::assertSame([], $occurrences->heldOf('t1', 'e1', 'inconnu', '2026-10-01', '2026-10-31'));
        self::assertSame([], $occurrences->heldOf('t2', 'e1', 'ec1', '2026-10-01', '2026-10-31'), 'jamais d\'un autre client');

        $data = $this->api('GET', '/timetable/consumption?course_unit_id=ec1&as_of=2026-10-15')[1]['data'];
        self::assertSame(360, $data['realized_minutes']);
        self::assertSame(['cm' => 120, 'td' => 240], $data['realized_minutes_by_kind']);
    }
}
