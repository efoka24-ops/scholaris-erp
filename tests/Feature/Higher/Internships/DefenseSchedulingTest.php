<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Internships;

use Tests\Support\Higher\Internships\InternshipsTestCase;

/** API-18 : programmation des soutenances ; RG-STG-1 (quatre conditions), RG-STG-2 (jury) et conflits de créneau. */
final class DefenseSchedulingTest extends InternshipsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInternships();
    }

    public function testChaqueConditionManquanteEmpecheLaProgrammation(): void
    {
        $id = (string) $this->convention()['id'];
        [$status, $problem] = $this->schedule($id);
        self::assertSame(409, $status);
        foreach (['convention_signed', 'completion_certificate', 'thesis_deposited', 'financial_clearance'] as $condition) {
            self::assertStringContainsString($condition, (string) $problem['detail']);
        }

        $id = (string) $this->signed('p2')['id'];
        $this->ok('POST', '/higher/internships/' . $id . '/completion', ['received_on' => '2026-09-02'], self::OFFICER);
        $this->ok('POST', '/higher/internships/' . $id . '/thesis', ['title' => 'Mémoire', 'file_ref' => 'm.pdf'], self::OFFICER);
        [$status, $problem] = $this->schedule($id);
        self::assertSame(409, $status, 'Sans quitus financier.');
        self::assertStringContainsString('financial_clearance', (string) $problem['detail']);
        $readiness = $this->ok('GET', '/higher/internships/' . $id . '/readiness', null, self::OFFICER);
        self::assertSame(['financial_clearance'], $readiness['missing']);

        $this->fakes->quitus['p2'] = true;
        [$status, $defense] = $this->schedule($id);
        self::assertSame(201, $status);
        self::assertSame('scheduled', $defense['data']['status']);
        self::assertCount(3, $defense['data']['jury']);
        self::assertSame(409, $this->schedule($id, ['held_on' => '2026-10-21'])[0], 'Une seule soutenance active par stage.');
    }

    public function testCompositionDuJury(): void
    {
        $id = $this->ready();
        $cases = [
            'trop peu de membres' => [['person_id' => 'j1', 'jury_role' => 'president'], ['person_id' => 'sup1', 'jury_role' => 'supervisor']],
            'encadreur président' => [['person_id' => 'sup1', 'jury_role' => 'president'], ['person_id' => 'j1', 'jury_role' => 'member'], ['person_id' => 'j2', 'jury_role' => 'member']],
            'encadreur absent' => [['person_id' => 'j1', 'jury_role' => 'president'], ['person_id' => 'j2', 'jury_role' => 'member'], ['person_id' => 'j3', 'jury_role' => 'member']],
            'deux présidents' => [['person_id' => 'j1', 'jury_role' => 'president'], ['person_id' => 'sup1', 'jury_role' => 'supervisor'], ['person_id' => 'j2', 'jury_role' => 'president']],
        ];
        foreach ($cases as $label => $jury) {
            [$status, $problem] = $this->schedule($id, ['jury' => $jury]);
            self::assertSame(422, $status, $label);
            self::assertArrayHasKey('jury', $problem['errors'], $label);
        }

        $this->settings->set(self::E, 'higher.defense.jury_min', 4, $this->org['school']);
        self::assertSame(422, $this->schedule($id)[0], 'Minimum paramétré à 4 pour l\'école.');
        $four = [...self::jury(), ['person_id' => 'j3', 'jury_role' => 'member']];
        self::assertSame(201, $this->schedule($id, ['jury' => $four])[0]);
    }

    public function testConflitsDeSalleEtDeJury(): void
    {
        $first = $this->ready('p1');
        $second = $this->ready('p2');
        self::assertSame(201, $this->schedule($first)[0]);

        [$status, $problem] = $this->schedule($second, ['start_time' => '09:30', 'end_time' => '10:30', 'jury' => [
            ['person_id' => 'j3', 'jury_role' => 'president'], ['person_id' => 'sup1', 'jury_role' => 'supervisor'], ['person_id' => 'j2', 'jury_role' => 'member'],
        ], 'room' => 'Salle 12']);
        self::assertSame(409, $status, 'Membre du jury déjà pris.');
        self::assertStringContainsString('membre du jury', (string) $problem['detail']);

        [$status, $problem] = $this->schedule($second, ['start_time' => '09:30', 'end_time' => '10:30', 'room' => 'amphi a']);
        self::assertSame(409, $status, 'Même salle.');
        self::assertStringContainsString('Salle', (string) $problem['detail']);

        self::assertSame(422, $this->schedule($second, ['start_time' => '11:00', 'end_time' => '10:00'])[0]);
        [$status, $defense] = $this->schedule($second, ['start_time' => '10:00', 'end_time' => '11:00']);
        self::assertSame(201, $status, 'Créneau suivant, bornes non chevauchantes.');

        [$status] = $this->call('PATCH', '/higher/defenses/' . $defense['data']['id'], ['held_on' => '2026-10-20', 'start_time' => '09:15', 'end_time' => '10:15', 'room' => 'Salle 3'], self::OFFICER);
        self::assertSame(409, $status, 'Reprogrammation en conflit de jury.');
        $moved = $this->ok('PATCH', '/higher/defenses/' . $defense['data']['id'], ['held_on' => '2026-10-21', 'start_time' => '09:00', 'end_time' => '10:00', 'room' => 'Amphi A'], self::OFFICER);
        self::assertSame('2026-10-21', $moved['held_on']);

        $cancelled = $this->ok('POST', '/higher/defenses/' . $moved['id'] . '/cancel', ['reason' => 'Jury indisponible'], self::OFFICER);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(201, $this->schedule($second, ['held_on' => '2026-10-22'])[0], 'Une soutenance annulée libère le stage.');
    }
}
