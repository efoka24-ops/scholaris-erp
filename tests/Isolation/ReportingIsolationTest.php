<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Reporting\ReportingTestCase;

/** Reporting : aucun indicateur d'un autre client ou d'un établissement non autorisé. */
final class ReportingIsolationTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        $this->bootReporting();
        $this->access->grant('director', 'e1');
        $this->access->grant('mallory', 'x1');
        $this->permissions->grant('director', 'reporting.read');
        $this->permissions->grant('mallory', 'reporting.read');
        $this->permissions->grant('mallory', 'reporting.group.read');
        $this->enrollments->add('t1', 'en1', 'l1', 'e1');
        $this->enrollments->add('t2', 'en9', 'z1', 'x1');
        $this->enrollments->add('t2', 'en10', 'z2', 'x1');
    }

    public function testUnEtablissementDUnAutreClientEstRefuse(): void
    {
        self::assertSame(403, $this->api('/dashboards/direction', 'mallory', 't2', 'e1')[0]->getStatusCode());
        self::assertSame(403, $this->api('/dashboards/direction', 'director', 't1', 'e2')[0]->getStatusCode());
    }

    public function testLesChiffresNeMelangentPasLesClients(): void
    {
        $t1 = $this->byKey($this->api('/dashboards/direction', 'director', 't1', 'e1')[1]['data']);
        $t2 = $this->byKey($this->api('/dashboards/direction', 'mallory', 't2', 'x1')[1]['data']);

        self::assertSame(1, $t1['people.enrolled']['value']);
        self::assertSame(2, $t2['people.enrolled']['value']);
    }

    public function testLaVueGroupeNeListeQueLesEtablissementsDuClient(): void
    {
        $data = $this->api('/dashboards/groupe', 'mallory', 't2')[1]['data'];

        self::assertSame(['x1'], array_column($data['establishments'], 'establishment_id'));
    }

    public function testRoutesProtegees(): void
    {
        $response = $this->kernel->handle(new \Nyholm\Psr7\ServerRequest('GET', '/api/v1/dashboards/direction'));
        self::assertSame(401, $response->getStatusCode());
    }
}
