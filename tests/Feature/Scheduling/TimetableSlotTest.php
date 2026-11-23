<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use Tests\Support\Scheduling\SchedulingTestCase;

/** Créneaux : création, conflits (409 avec détail), cours mutualisés, plages du soir et du samedi, capacité. */
final class TimetableSlotTest extends SchedulingTestCase
{
    protected function setUp(): void
    {
        $this->bootScheduling();
    }

    public function testCreationEtLecture(): void
    {
        $slot = $this->slot();

        self::assertSame('day', $slot['period_of_day']);
        self::assertSame(['c1'], $slot['structure_ids']);
        self::assertArrayNotHasKey('tenant_id', $slot);
        self::assertSame($slot['id'], $this->api('GET', '/timetable-slots/' . $slot['id'])[1]['data']['id']);
        self::assertCount(1, $this->api('GET', '/timetable-slots?filter[structure_id]=c1')[1]['data']);
        self::assertCount(0, $this->api('GET', '/timetable-slots?filter[structure_id]=c9')[1]['data']);
    }

    public function testMemeEnseignantSurPlageChevauchanteEst409AvecLeDetail(): void
    {
        $first = $this->slot();
        [$response, $json] = $this->api('POST', '/timetable-slots', body: $this->slotBody(['room_id' => 'r2', 'structure_ids' => ['c2'], 'start_time' => '09:00', 'end_time' => '11:00']));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('teacher', $json['conflicts'][0]['kind']);
        self::assertSame($first['id'], $json['conflicts'][0]['slot_id']);
        self::assertStringContainsString('problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testMemeSalleEtMemeClasseSontEnConflit(): void
    {
        $this->slot();
        $room = $this->api('POST', '/timetable-slots', body: $this->slotBody(['teacher_person_id' => 'p-t2', 'structure_ids' => ['c2']]));
        $class = $this->api('POST', '/timetable-slots', body: $this->slotBody(['teacher_person_id' => 'p-t2', 'room_id' => 'r2']));

        self::assertSame(409, $room[0]->getStatusCode());
        self::assertSame('room', $room[1]['conflicts'][0]['kind']);
        self::assertSame(409, $class[0]->getStatusCode());
        self::assertSame('structure', $class[1]['conflicts'][0]['kind']);
        self::assertSame(['c1'], $class[1]['conflicts'][0]['structure_ids']);
    }

    public function testPlagesJointivesEtAutreJourSontAcceptees(): void
    {
        $this->slot();

        self::assertSame(201, $this->code('POST', '/timetable-slots', body: $this->slotBody(['start_time' => '10:00', 'end_time' => '12:00'])));
        self::assertSame(201, $this->code('POST', '/timetable-slots', body: $this->slotBody(['weekday' => 2])));
    }

    public function testPeriodesDeValiditeDisjointesSontAcceptees(): void
    {
        $this->slot(['valid_to' => '2027-01-31']);

        self::assertSame(201, $this->code('POST', '/timetable-slots', body: $this->slotBody(['valid_from' => '2027-02-01'])));
    }

    public function testUnCoursMutualiseEstUnSeulCreneauPortantPlusieursStructures(): void
    {
        $slot = $this->slot(['structure_ids' => ['c1', 'c2']]);

        self::assertSame(['c1', 'c2'], $slot['structure_ids']);
        self::assertCount(1, $this->api('GET', '/timetable-slots?filter[structure_id]=c2')[1]['data']);
        // une des deux classes ne peut pas avoir un autre cours en même temps
        self::assertSame(409, $this->code('POST', '/timetable-slots', body: $this->slotBody(['teacher_person_id' => 'p-t2', 'room_id' => 'r2', 'structure_ids' => ['c2']])));
    }

    public function testCoursDuSoirEtDuSamedi(): void
    {
        $evening = $this->slot(['weekday' => 3, 'start_time' => '18:00', 'end_time' => '21:00']);
        $saturday = $this->slot(['weekday' => 6, 'start_time' => '08:00', 'end_time' => '13:00']);

        self::assertSame('evening', $evening['period_of_day']);
        self::assertSame(6, $saturday['weekday']);
        self::assertCount(1, $this->api('GET', '/timetable-slots?filter[period_of_day]=evening')[1]['data']);
    }

    public function testVerificationSansEnregistrer(): void
    {
        $existing = $this->slot();

        [$response, $json] = $this->api('POST', '/timetable-slots/check', body: $this->slotBody(['structure_ids' => ['c2'], 'room_id' => 'r2']));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($json['data']['ok']);
        self::assertSame('teacher', $json['data']['conflicts'][0]['kind']);

        // en excluant le créneau existant (modification), plus de conflit
        $ignoring = $this->api('POST', '/timetable-slots/check', body: $this->slotBody(['ignore_slot_id' => $existing['id']]))[1];
        self::assertTrue($ignoring['data']['ok']);
        self::assertCount(1, $this->api('GET', '/timetable-slots')[1]['data'], 'la vérification n\'enregistre rien');
    }

    public function testModificationRevoitLesConflitsSansEnEtreEnConflitAvecElleMeme(): void
    {
        $slot = $this->slot();
        $other = $this->slot(['room_id' => 'r2', 'structure_ids' => ['c2'], 'start_time' => '10:00', 'end_time' => '12:00']);

        self::assertSame(200, $this->code('PATCH', '/timetable-slots/' . $slot['id'], body: ['subject_label' => 'Physique']));
        self::assertSame(409, $this->code('PATCH', '/timetable-slots/' . $slot['id'], body: ['end_time' => '11:00']));
        self::assertSame(200, $this->code('PATCH', '/timetable-slots/' . $other['id'], body: ['structure_ids' => ['c2', 'c3']]));
    }

    public function testCapaciteDeSalleInsuffisante(): void
    {
        $this->rooms->set('r1', 1);
        $this->enrollments->add('t1', 'en3', 'p-l3', 'e1', 'c1');

        [$response, $json] = $this->api('POST', '/timetable-slots', body: $this->slotBody());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('capacity', $json['conflicts'][0]['kind']);

        $this->rooms->set('r1', 30);
        self::assertSame(201, $this->code('POST', '/timetable-slots', body: $this->slotBody()));
    }

    public function testSansCapaciteConnueAucunControle(): void
    {
        self::assertSame(201, $this->code('POST', '/timetable-slots', body: $this->slotBody()));
    }

    public function testValidation(): void
    {
        self::assertSame(422, $this->code('POST', '/timetable-slots', body: $this->slotBody(['end_time' => '07:00'])));
        self::assertSame(422, $this->code('POST', '/timetable-slots', body: $this->slotBody(['start_time' => '8h'])));
        self::assertSame(422, $this->code('POST', '/timetable-slots', body: $this->slotBody(['weekday' => 8])));
        self::assertSame(422, $this->code('POST', '/timetable-slots', body: $this->slotBody(['structure_ids' => []])));
        self::assertSame(422, $this->code('POST', '/timetable-slots', body: $this->slotBody(['valid_to' => '2026-01-01'])));
        self::assertSame(422, $this->code('POST', '/timetable-slots', body: $this->slotBody(['teacher_person_id' => 'inconnu'])));
        self::assertSame(422, $this->code('POST', '/timetable-slots', body: $this->slotBody(['tenant_id' => 't2'])));
    }

    public function testSuppressionEtPermissions(): void
    {
        $slot = $this->slot();
        $this->access->grant('lecteur', 'e1');
        $this->permissions->grant('lecteur', 'scheduling.read');

        self::assertSame(403, $this->code('POST', '/timetable-slots', 'lecteur', body: $this->slotBody(['weekday' => 4])));
        self::assertSame(403, $this->code('DELETE', '/timetable-slots/' . $slot['id'], 'lecteur'));
        self::assertSame(200, $this->code('GET', '/timetable-slots', 'lecteur'));
        self::assertSame(204, $this->code('DELETE', '/timetable-slots/' . $slot['id']));
        self::assertSame(404, $this->code('GET', '/timetable-slots/' . $slot['id']));
    }
}
