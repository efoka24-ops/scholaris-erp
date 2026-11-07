<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Attendance;

use Tests\Feature\Nursery\Pickup\PickupTestCase;

/** EX-MAT-23, 24 : appel de la section (aide ou éducatrice), arrivées dans le journal, absents lus par le socle. */
final class CallTest extends PickupTestCase
{
    protected function setUp(): void
    {
        $this->bootPickup([], '2026-10-21T08:05:00+01:00');
    }

    public function testLAppelEnregistreLesPresentsParDefautEtLesExceptionsParLeContratDuSocle(): void
    {
        $cleo = $this->world->child(self::TA, self::EA, $this->sectionA, 'Cleo');
        $result = $this->ok('POST', '/nursery/sections/' . $this->sectionA . '/call', [
            'day_part' => 'morning', 'client_uuid' => 'appel-0001', 'validate' => true,
            'exceptions' => [['child_id' => $cleo['child'], 'status' => 'A', 'note' => 'Malade, parent prévenu (fictif)']],
        ], 'educatrice-a');
        self::assertSame(1, $result['present']);
        self::assertSame(1, $result['absent']);
        self::assertSame('validated', $result['status']);
        self::assertSame([$cleo['child']], $this->world->attendance->absentOn(self::TA, self::EA, '2026-10-21', $this->sectionA));
        self::assertContains('nursery.call.recorded', $this->audit->actions());

        $roll = $this->ok('GET', '/nursery/sections/' . $this->sectionA . '/roll', null, 'educatrice-a');
        $byChild = array_column($roll, null, 'child_id');
        self::assertSame('present', $byChild[$this->ada['child']]['status']);
        self::assertSame('absent', $byChild[$cleo['child']]['status']);
    }

    public function testLeRejeuDeLAppelNeCreeNiSeanceNiArriveeEnDouble(): void
    {
        $body = ['day_part' => 'morning', 'client_uuid' => 'appel-0002'];
        $this->ok('POST', '/nursery/sections/' . $this->sectionA . '/call', $body, 'educatrice-a');
        $this->ok('POST', '/nursery/sections/' . $this->sectionA . '/call', $body, 'educatrice-a');
        self::assertCount(1, $this->events('arrival'));
        self::assertCount(1, $this->world->attendance->sessions);
    }

    public function testUneAutreSectionEstInterditeEn403JournaliseEtInconnueEn404(): void
    {
        $body = ['day_part' => 'morning', 'client_uuid' => 'appel-0003'];
        self::assertSame(403, $this->code('POST', '/nursery/sections/' . $this->sectionA . '/call', $body, 'educatrice-b'));
        self::assertContains('nursery.scope.denied', $this->audit->actions());
        self::assertSame(404, $this->code('GET', '/nursery/sections/' . $this->sectionA . '/roll', null, 'educatrice-b'));
        self::assertSame(404, $this->code('POST', '/nursery/sections/inconnue/call', $body, 'educatrice-a'));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('POST', '/nursery/sections/' . $this->sectionA . '/call', $body, 'directrice-b', self::TB, self::EB));
    }

    public function testValidationDeLAppel(): void
    {
        self::assertSame(422, $this->code('POST', '/nursery/sections/' . $this->sectionA . '/call', ['day_part' => 'soir', 'client_uuid' => 'a'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/sections/' . $this->sectionA . '/call', ['day_part' => 'morning'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/sections/' . $this->sectionA . '/call', ['day_part' => 'morning', 'client_uuid' => 'a', 'exceptions' => [['child_id' => 'x', 'status' => 'P']]], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/sections/' . $this->sectionA . '/call', ['day_part' => 'morning', 'client_uuid' => 'a', 'exceptions' => [['child_id' => $this->bao['child'], 'status' => 'A']]], 'educatrice-a'), 'Enfant d’une autre section.');
    }

    public function testUneArriveeTardiveEstUniqueParEnfantEtParJour(): void
    {
        $first = $this->ok('POST', '/nursery/children/' . $this->ada['child'] . '/arrival', ['arrived_at' => '2026-10-21T09:10:00+01:00'], 'educatrice-a');
        self::assertTrue($first['recorded']);
        self::assertFalse($this->ok('POST', '/nursery/children/' . $this->ada['child'] . '/arrival', [], 'educatrice-a')['recorded']);
        self::assertSame(403, $this->code('POST', '/nursery/children/' . $this->ada['child'] . '/arrival', [], 'educatrice-b'));
        self::assertSame(404, $this->code('POST', '/nursery/children/inconnu/arrival', [], 'educatrice-a'));
    }
}
