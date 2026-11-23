<?php

declare(strict_types=1);

namespace Tests\Support\Portals;

use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Tests\Support\Reporting\ReportingTestCase;

/** Base des tests Portals : une famille (parent p1, deux enfants dans deux établissements du groupe). */
abstract class PortalsTestCase extends ReportingTestCase
{
    /** @param array<string, mixed> $extra liaisons supplémentaires (sections de portail des applications) */
    protected function bootPortals(array $extra = []): void
    {
        $this->bootReporting($extra);
        $this->people->add('t1', 'p1', 'Marie', 'Foka');
        $this->people->add('t1', 'c1', 'Léa', 'Foka');
        $this->people->add('t1', 'c2', 'Paul', 'Foka');
        $this->people->link('t1', 'p1', 'c1');
        $this->people->link('t1', 'p1', 'c2');
        $this->actors->link('t1', 'parent', 'p1');
        $this->enrollments->add('t1', 'en1', 'c1', 'e1');
        $this->enrollments->add('t1', 'en2', 'c2', 'e2');
        $this->debtors->setBalance('t1', 'e1', 'c1', 150000);
        $this->debtors->setBalance('t1', 'e2', 'c2', 25000);
        $this->results->add('t1', new PublishedResult('r1', 'c1', 'e1', 'per1', 'Séquence 1', 13.5, 4, 32, 'Encouragements'));
        $this->results->add('t1', new PublishedResult('r2', 'c2', 'e2', 'per1', 'Séquence 1', 9.0, 20, 30, null));
    }
}
