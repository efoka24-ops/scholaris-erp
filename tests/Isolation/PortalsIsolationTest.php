<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Portals\PortalsTestCase;

/** Portals : un parent ne voit que les enfants de son client et de ses propres liens. */
final class PortalsIsolationTest extends PortalsTestCase
{
    protected function setUp(): void
    {
        $this->bootPortals();
        $this->people->add('t2', 'p9', 'Autre', 'Parent');
        $this->people->add('t2', 'c9', 'Autre', 'Enfant');
        $this->people->link('t2', 'p9', 'c9');
        $this->actors->link('t2', 'parent2', 'p9');
        $this->enrollments->add('t2', 'en9', 'c9', 'x1');
        $this->debtors->setBalance('t2', 'x1', 'c9', 999);
        // Lien parasite : le parent t1 « garant » d'un enfant d'un autre client ne doit rien exposer.
        $this->people->link('t1', 'p1', 'c9');
    }

    public function testChaqueParentNeVoitQueSesEnfants(): void
    {
        $t1 = $this->api('/portal/family', 'parent')[1]['data'];
        $t2 = $this->api('/portal/family', 'parent2', 't2')[1]['data'];

        self::assertSame(['c1', 'c2'], array_column(array_column($t1['children'], 'person'), 'id'));
        self::assertSame(['c9'], array_column(array_column($t2['children'], 'person'), 'id'));
        self::assertSame(999, $t2['family_balance_xaf']);
    }

    public function testUnJetonDUnAutreClientNeDonneAucuneFicheDuPremier(): void
    {
        self::assertSame(403, $this->api('/portal/family', 'parent', 't2')[0]->getStatusCode());
        self::assertSame(403, $this->api('/portal/learner', 'parent', 't2')[0]->getStatusCode());
    }
}
