<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use Tests\Support\Scheduling\SchedulingTestCase;

/** Calendrier académique : semestres, examens, rattrapages, délibérations, jours fériés. */
final class CalendarTest extends SchedulingTestCase
{
    protected function setUp(): void
    {
        $this->bootScheduling();
    }

    public function testLesTypesDEntreeDuCalendrierAcademique(): void
    {
        foreach ([
            ['semester', 'Semestre 1', '2026-09-07', '2027-01-29'],
            ['exam_period', 'Examens du semestre 1', '2027-01-11', '2027-01-22'],
            ['makeup_session', 'Rattrapage du semestre 1', '2027-02-01', '2027-02-05'],
            ['deliberation', 'Délibération', '2027-02-10', '2027-02-10'],
            ['holiday', 'Fête nationale', '2027-05-20', '2027-05-20'],
        ] as [$kind, $title, $from, $to]) {
            self::assertSame(201, $this->code('POST', '/calendar-events', body: ['kind' => $kind, 'title' => $title, 'starts_on' => $from, 'ends_on' => $to]), $kind);
        }

        self::assertCount(5, $this->api('GET', '/calendar-events')[1]['data']);
        self::assertCount(1, $this->api('GET', '/calendar-events?filter[kind]=exam_period')[1]['data']);
        self::assertCount(2, $this->api('GET', '/calendar-events?filter[from]=2027-01-25&filter[to]=2027-02-07')[1]['data'], 'entrées qui touchent la fenêtre');
    }

    public function testUneJourneeUniqueSansDateDeFin(): void
    {
        $event = $this->api('POST', '/calendar-events', body: ['kind' => 'deliberation', 'title' => 'Jury', 'starts_on' => '2027-02-10'])[1]['data'];

        self::assertSame('2027-02-10', $event['ends_on']);
        self::assertArrayNotHasKey('tenant_id', $event);
    }

    public function testValidationEtModification(): void
    {
        self::assertSame(422, $this->code('POST', '/calendar-events', body: ['kind' => 'inconnu', 'title' => 'x', 'starts_on' => '2027-01-01']));
        self::assertSame(422, $this->code('POST', '/calendar-events', body: ['kind' => 'event', 'title' => 'x', 'starts_on' => '2027-01-10', 'ends_on' => '2027-01-01']));
        self::assertSame(422, $this->code('POST', '/calendar-events', body: ['kind' => 'event', 'title' => 'x', 'starts_on' => '10/01/2027']));
        self::assertSame(422, $this->code('POST', '/calendar-events', body: ['kind' => 'event', 'title' => 'x', 'starts_on' => '2027-01-01', 'tenant_id' => 't2']));
        self::assertSame(422, $this->code('GET', '/calendar-events?filter[from]=hier'));

        $id = $this->api('POST', '/calendar-events', body: ['kind' => 'event', 'title' => 'Portes ouvertes', 'starts_on' => '2027-03-01'])[1]['data']['id'];
        self::assertSame('Journée portes ouvertes', $this->api('PATCH', '/calendar-events/' . $id, body: ['title' => 'Journée portes ouvertes'])[1]['data']['title']);
        self::assertSame(422, $this->code('PATCH', '/calendar-events/' . $id, body: ['ends_on' => '2026-01-01']));
        self::assertSame(204, $this->code('DELETE', '/calendar-events/' . $id));
        self::assertSame(404, $this->code('GET', '/calendar-events/' . $id));
    }

    public function testEcritureReserveAuxGestionnaires(): void
    {
        $this->access->grant('lecteur', 'e1');
        $this->permissions->grant('lecteur', 'scheduling.read');
        $this->access->grant('inconnu', 'e1');

        self::assertSame(403, $this->code('POST', '/calendar-events', 'lecteur', body: ['kind' => 'event', 'title' => 'x', 'starts_on' => '2027-01-01']));
        self::assertSame(200, $this->code('GET', '/calendar-events', 'lecteur'));
        self::assertSame(403, $this->code('GET', '/calendar-events', 'inconnu'), 'ni droit ni fiche de personne');
        self::assertSame(401, $this->call('GET', '/api/v1/calendar-events')->getStatusCode());
    }

    public function testUnJourFerieSuspendLesCours(): void
    {
        $this->slot();
        $directory = $this->kernel->container()->get(\Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory::class);
        self::assertCount(1, $directory->slotsOn('t1', 'e1', '2026-10-12'));

        $this->api('POST', '/calendar-events', body: ['kind' => 'holiday', 'title' => 'Jour férié', 'starts_on' => '2026-10-12']);
        self::assertSame([], $directory->slotsOn('t1', 'e1', '2026-10-12'));
        self::assertCount(1, $directory->slotsOn('t1', 'e1', '2026-10-19'));

        // une fermeture limitée à une classe ne touche pas les autres
        $this->slot(['teacher_person_id' => 'p-t2', 'room_id' => 'r2', 'structure_ids' => ['c2'], 'weekday' => 2]);
        $this->api('POST', '/calendar-events', body: ['kind' => 'closure', 'title' => 'Fermeture c2', 'starts_on' => '2026-10-13', 'structure_id' => 'c2']);
        self::assertSame([], $directory->slotsOn('t1', 'e1', '2026-10-13'));
        self::assertCount(1, $directory->slotsOn('t1', 'e1', '2026-10-20'));
    }
}
