<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Tests\Support\Scheduling\SchedulingTestCase;

/** Modifications ponctuelles : cours annulé, déplacé, remplacé ; effet sur l'annuaire et notifications. */
final class TimetableChangeTest extends SchedulingTestCase
{
    /** @var array<string, mixed> */
    private array $slot = [];

    protected function setUp(): void
    {
        $this->bootScheduling();
        $this->slot = $this->slot();
    }

    private function directory(): TimetableDirectory
    {
        return $this->kernel->container()->get(TimetableDirectory::class);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function change(string $kind, array $extra = [], string $date = '2026-10-12'): array
    {
        return $this->api('POST', '/timetable-changes', body: ['slot_id' => $this->slot['id'], 'on_date' => $date, 'kind' => $kind] + $extra);
    }

    public function testUnCoursAnnuleDisparaitDeLAnnuaireCeJourLaSeulement(): void
    {
        [$response, $json] = $this->change('cancelled', ['reason' => 'Grève']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('cancelled', $json['data']['kind']);
        self::assertSame([], $this->directory()->slotsOn('t1', 'e1', '2026-10-12'));
        self::assertSame([], $this->directory()->teacherSlotsOn('t1', 'p-t1', '2026-10-12'));
        self::assertCount(1, $this->directory()->slotsOn('t1', 'e1', '2026-10-19'));
        self::assertCount(1, $this->directory()->teacherSlotsOn('t1', 'p-t1', '2026-10-19'));
    }

    public function testLEnseignantLesApprenantsEtLesGarantsSontNotifies(): void
    {
        $json = $this->change('cancelled', ['reason' => 'Grève'])[1];

        $sent = $this->messenger->of('timetable_change');
        $persons = array_map(static fn (array $m): string => $m['person'], $sent);
        sort($persons);
        self::assertSame(['p-g1', 'p-l1', 'p-t1'], $persons);
        self::assertSame('annulé', $sent[0]['variables']['change_label']);
        self::assertSame('2026-10-12', $sent[0]['variables']['date']);
        self::assertSame('08:00-10:00', $sent[0]['variables']['time']);
        self::assertSame('Mathématiques', $sent[0]['variables']['subject']);
        self::assertStringContainsString('Grève', (string) $sent[0]['variables']['details']);
        self::assertSame('Collège Les Palmiers', $sent[0]['variables']['establishment_name']);
        self::assertSame('e1', $sent[0]['establishment']);
        self::assertSame(3, $json['data']['notified_count']);
        self::assertNotNull($json['data']['notified_at']);
    }

    public function testUnEchecDEnvoiNeBloquePasLaModification(): void
    {
        $this->messenger->fail = true;
        $json = $this->change('cancelled')[1];

        self::assertSame(0, $json['data']['notified_count']);
        self::assertSame([], $this->directory()->slotsOn('t1', 'e1', '2026-10-12'));
    }

    public function testUnCoursDeplaceApparaitAilleursAvecSonNouvelHoraire(): void
    {
        $json = $this->change('moved', ['new_date' => '2026-10-14', 'new_start_time' => '14:00', 'new_end_time' => '16:00', 'new_room_id' => 'r2'])[1];

        self::assertSame('2026-10-14', $json['data']['new_date']);
        self::assertSame([], $this->directory()->slotsOn('t1', 'e1', '2026-10-12'));
        $moved = $this->directory()->slotsOn('t1', 'e1', '2026-10-14');
        self::assertCount(1, $moved);
        self::assertSame($this->slot['id'], $moved[0]->id);
        self::assertSame(['14:00', '16:00', 'r2', 3, 'c1'], [$moved[0]->startTime, $moved[0]->endTime, $moved[0]->roomId, $moved[0]->weekday, $moved[0]->structureId]);
        self::assertCount(1, $this->directory()->teacherSlotsOn('t1', 'p-t1', '2026-10-14'));
        self::assertStringContainsString('2026-10-14 14:00-16:00', (string) $this->messenger->of('timetable_change')[0]['variables']['details']);
    }

    public function testUnDeplacementSurLeMemeJourChangeSeulementLHoraire(): void
    {
        $this->change('moved', ['new_start_time' => '13:00', 'new_end_time' => '15:00']);

        $slots = $this->directory()->slotsOn('t1', 'e1', '2026-10-12');
        self::assertCount(1, $slots);
        self::assertSame('13:00', $slots[0]->startTime);
    }

    public function testUnDeplacementEnConflitEstRefuse(): void
    {
        $this->slot(['weekday' => 3, 'start_time' => '14:00', 'end_time' => '16:00', 'structure_ids' => ['c2'], 'room_id' => 'r2']);

        [$response, $json] = $this->change('moved', ['new_date' => '2026-10-14', 'new_start_time' => '15:00', 'new_end_time' => '17:00']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('teacher', $json['conflicts'][0]['kind']);
        self::assertSame([], $this->messenger->sent, 'pas de notification pour une modification refusée');
        self::assertCount(1, $this->directory()->slotsOn('t1', 'e1', '2026-10-12'));
    }

    public function testUnDeplacementVersUnJourFerieEstRefuse(): void
    {
        $this->api('POST', '/calendar-events', body: ['kind' => 'holiday', 'title' => 'Férié', 'starts_on' => '2026-10-14']);

        self::assertSame(422, $this->change('moved', ['new_date' => '2026-10-14'])[0]->getStatusCode());
    }

    public function testUnCoursRemplaceEstAssureParLAutreEnseignant(): void
    {
        $json = $this->change('replaced', ['new_teacher_person_id' => 'p-t2'])[1];

        self::assertSame('p-t2', $json['data']['new_teacher_person_id']);
        self::assertSame([], $this->directory()->teacherSlotsOn('t1', 'p-t1', '2026-10-12'));
        $replacement = $this->directory()->teacherSlotsOn('t1', 'p-t2', '2026-10-12');
        self::assertCount(1, $replacement);
        self::assertSame('p-t2', $this->directory()->slotsOn('t1', 'e1', '2026-10-12')[0]->teacherPersonId);
        self::assertCount(1, $this->directory()->teacherSlotsOn('t1', 'p-t1', '2026-10-19'), 'la semaine suivante reste à l\'enseignant titulaire');

        $persons = array_map(static fn (array $m): string => $m['person'], $this->messenger->of('timetable_change'));
        sort($persons);
        self::assertSame(['p-g1', 'p-l1', 'p-t1', 'p-t2'], $persons);
        self::assertStringContainsString('Remplaçant : Marie Test', (string) $this->messenger->of('timetable_change')[0]['variables']['details']);
    }

    public function testUnRemplacantDejaOccupeEstRefuse(): void
    {
        $this->slot(['teacher_person_id' => 'p-t2', 'room_id' => 'r2', 'structure_ids' => ['c2']]);

        [$response, $json] = $this->change('replaced', ['new_teacher_person_id' => 'p-t2']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('teacher', $json['conflicts'][0]['kind']);
    }

    public function testValidationDesModifications(): void
    {
        self::assertSame(422, $this->change('cancelled', [], '2026-10-13')[0]->getStatusCode(), 'ce n\'est pas un lundi');
        self::assertSame(422, $this->change('cancelled', [], '2026-08-31')[0]->getStatusCode(), 'avant la validité');
        self::assertSame(422, $this->change('cancelled', ['new_room_id' => 'r2'])[0]->getStatusCode());
        self::assertSame(422, $this->change('moved')[0]->getStatusCode(), 'rien à déplacer');
        self::assertSame(422, $this->change('moved', ['new_start_time' => '11:00', 'new_end_time' => '10:30'])[0]->getStatusCode());
        self::assertSame(422, $this->change('replaced')[0]->getStatusCode());
        self::assertSame(422, $this->change('replaced', ['new_teacher_person_id' => 'p-t1'])[0]->getStatusCode());
        self::assertSame(422, $this->change('replaced', ['new_teacher_person_id' => 'inconnu'])[0]->getStatusCode());
        self::assertSame(422, $this->change('annule')[0]->getStatusCode());
        self::assertSame(404, $this->api('POST', '/timetable-changes', body: ['slot_id' => 'zzz', 'on_date' => '2026-10-12', 'kind' => 'cancelled'])[0]->getStatusCode());
    }

    public function testUneOccurrenceNeSeModifieQuUneFoisEtPeutEtreRestauree(): void
    {
        $id = $this->change('cancelled')[1]['data']['id'];

        self::assertSame(409, $this->change('moved', ['new_date' => '2026-10-14'])[0]->getStatusCode());
        self::assertSame(204, $this->code('DELETE', '/timetable-changes/' . $id));
        self::assertCount(1, $this->directory()->slotsOn('t1', 'e1', '2026-10-12'));
        self::assertSame(404, $this->code('GET', '/timetable-changes/' . $id));
    }

    public function testListeEtFiltres(): void
    {
        $this->change('cancelled');
        $this->change('cancelled', [], '2026-10-19');

        self::assertCount(2, $this->api('GET', '/timetable-changes')[1]['data']);
        self::assertCount(1, $this->api('GET', '/timetable-changes?filter[on_date]=2026-10-19')[1]['data']);
        self::assertCount(0, $this->api('GET', '/timetable-changes?filter[kind]=moved')[1]['data']);
        self::assertSame(422, $this->code('GET', '/timetable-changes?filter[inconnu]=1'));
    }

    public function testSupprimerUnCreneauSupprimeSesModifications(): void
    {
        $this->change('cancelled');
        $this->api('DELETE', '/timetable-slots/' . $this->slot['id']);

        self::assertSame([], $this->api('GET', '/timetable-changes')[1]['data']);
    }

    public function testLEcritureDemandeLeDroitDeGestion(): void
    {
        $this->access->grant('lecteur', 'e1');
        $this->permissions->grant('lecteur', 'scheduling.read');

        self::assertSame(403, $this->api('POST', '/timetable-changes', 'lecteur', body: ['slot_id' => $this->slot['id'], 'on_date' => '2026-10-12', 'kind' => 'cancelled'])[0]->getStatusCode());
    }
}
