<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Scheduling\SchedulingTestCase;

/** Aucune donnée du calendrier ni des emplois du temps ne franchit la frontière entre clients ou établissements. */
final class SchedulingIsolationTest extends SchedulingTestCase
{
    /** @var array<string, mixed> */
    private array $slot = [];
    private string $eventId = '';
    private string $changeId = '';

    protected function setUp(): void
    {
        $this->bootScheduling();
        $this->slot = $this->slot();
        $this->eventId = (string) $this->api('POST', '/calendar-events', body: ['kind' => 'event', 'title' => 'Rentrée', 'starts_on' => '2026-09-07'])[1]['data']['id'];
        $this->changeId = (string) $this->api('POST', '/timetable-changes', body: ['slot_id' => $this->slot['id'], 'on_date' => '2026-10-12', 'kind' => 'cancelled'])[1]['data']['id'];

        // « mallory » : gestionnaire d'un autre client (t2) ; « eve » : gestionnaire d'un autre établissement (e2) du même client.
        $this->access->grant('mallory', 'e1');
        $this->permissions->grant('mallory', 'scheduling.read', 'scheduling.manage');
        $this->access->grant('eve', 'e2');
        $this->permissions->grant('eve', 'scheduling.read', 'scheduling.manage');
    }

    public function testUnAutreClientNeVoitRienEtNeModifieRien(): void
    {
        $id = (string) $this->slot['id'];
        foreach ([
            ['GET', '/timetable-slots/' . $id], ['PATCH', '/timetable-slots/' . $id], ['DELETE', '/timetable-slots/' . $id],
            ['GET', '/calendar-events/' . $this->eventId], ['PATCH', '/calendar-events/' . $this->eventId], ['DELETE', '/calendar-events/' . $this->eventId],
            ['GET', '/timetable-changes/' . $this->changeId], ['DELETE', '/timetable-changes/' . $this->changeId],
        ] as [$method, $path]) {
            self::assertSame(404, $this->code($method, $path, 'mallory', 't2', 'e1', in_array($method, ['PATCH'], true) ? ['title' => 'x', 'subject_label' => 'x'] : null), $method . ' ' . $path);
        }
        foreach (['/timetable-slots', '/calendar-events', '/timetable-changes'] as $list) {
            self::assertSame([], $this->api('GET', $list, 'mallory', 't2')[1]['data'], $list);
        }
        self::assertSame(404, $this->code('POST', '/timetable-changes', 'mallory', 't2', 'e1', ['slot_id' => $id, 'on_date' => '2026-10-12', 'kind' => 'cancelled']));
        foreach ($this->api('GET', '/timetable?week=2026-10-14&structure_id=c1', 'mallory', 't2')[1]['data']['days'] as $day) {
            self::assertSame([], $day['entries']);
        }
        self::assertCount(1, $this->api('GET', '/timetable-slots')[1]['data'], 'rien n\'a été supprimé');
    }

    public function testUnAutreEtablissementDuMemeClientNeVoitRien(): void
    {
        $id = (string) $this->slot['id'];
        foreach ([
            ['GET', '/timetable-slots/' . $id], ['DELETE', '/timetable-slots/' . $id],
            ['GET', '/calendar-events/' . $this->eventId], ['DELETE', '/calendar-events/' . $this->eventId],
            ['GET', '/timetable-changes/' . $this->changeId], ['DELETE', '/timetable-changes/' . $this->changeId],
        ] as [$method, $path]) {
            self::assertSame(404, $this->code($method, $path, 'eve', 't1', 'e2'), $method . ' ' . $path);
        }
        foreach (['/timetable-slots', '/calendar-events', '/timetable-changes'] as $list) {
            self::assertSame([], $this->api('GET', $list, 'eve', 't1', 'e2')[1]['data'], $list);
        }
    }

    public function testUnEtablissementNeConfliteQueAvecLuiMeme(): void
    {
        // même enseignant, même salle, même heure, mais dans l'établissement e2 : aucun conflit
        self::assertSame(201, $this->code('POST', '/timetable-slots', 'eve', 't1', 'e2', $this->slotBody()));
        self::assertCount(1, $this->api('GET', '/timetable-slots')[1]['data']);
    }

    public function testLEtablissementActifDoitEtreAutorise(): void
    {
        self::assertSame(403, $this->code('GET', '/timetable-slots', 'eve', 't1', 'e1'));
    }

    public function testDeuxClientsPeuventAvoirLesMemesIdentifiantsMetier(): void
    {
        self::assertSame(201, $this->code('POST', '/timetable-slots', 'mallory', 't2', 'e1', $this->slotBody()));
        self::assertCount(1, $this->api('GET', '/timetable-slots')[1]['data']);
        self::assertCount(1, $this->api('GET', '/timetable-slots', 'mallory', 't2')[1]['data']);
    }

    public function testLAnnuaireNeFranchitPasLesClients(): void
    {
        $directory = $this->kernel->container()->get(\Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory::class);
        $this->api('POST', '/timetable-slots', 'mallory', 't2', 'e1', $this->slotBody(['weekday' => 2]));

        self::assertSame([], $directory->slotsOn('t3', 'e1', '2026-10-12'));
        self::assertCount(1, $directory->slotsOn('t1', 'e1', '2026-10-19'));
        self::assertSame([], $directory->teacherSlotsOn('t1', 'p-t1', '2026-10-13'), 'le créneau du mardi est celui du client t2');
        self::assertCount(1, $directory->teacherSlotsOn('t2', 'p-t1', '2026-10-13'));
    }
}
