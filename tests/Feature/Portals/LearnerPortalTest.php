<?php

declare(strict_types=1);

namespace Tests\Feature\Portals;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Tests\Support\Portals\PortalsTestCase;

final class LearnerPortalTest extends PortalsTestCase
{
    protected function setUp(): void
    {
        $this->bootPortals();
        $this->actors->link('t1', 'lea', 'c1');
        $this->timetable->add(new SlotInfo('s2', 't1', 'e1', 'c1', 'tx', 'Physique', 4, '10:00', '11:00', '2026-09-01'));
        $this->timetable->add(new SlotInfo('s1', 't1', 'e1', 'c1', 'tx', 'Maths', 4, '08:00', '09:00', '2026-09-01'));
        $this->timetable->add(new SlotInfo('s3', 't1', 'e1', 'autre-classe', 'tx', 'Latin', 4, '08:00', '09:00', '2026-09-01'));
    }

    public function testLApprenantVoitSaFicheSonSoldeSesResultatsEtSonEmploiDuTemps(): void
    {
        [$response, $json] = $this->api('/portal/learner', 'lea');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $json['data'];

        self::assertSame('c1', $data['person']['id']);
        self::assertSame('full', $data['access']);
        self::assertSame(150000, $data['balance_xaf']);
        self::assertSame(13.5, $data['results']['latest']['average']);
        self::assertSame(['Maths', 'Physique'], array_column($data['timetable_today'], 'subject'));
        self::assertSame('2026-10-08', $data['today']);
    }

    public function testLApprenantNeVoitJamaisLesDonneesDUnAutre(): void
    {
        $data = $this->api('/portal/learner', 'lea')[1]['data'];

        self::assertNotContains('r2', array_column($data['results']['items'], 'id'));
    }

    public function testCompteSansPersonneRefuse(): void
    {
        self::assertSame(403, $this->api('/portal/learner', 'inconnu')[0]->getStatusCode());
    }
}
