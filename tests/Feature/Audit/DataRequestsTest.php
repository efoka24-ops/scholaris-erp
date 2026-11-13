<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Scholaris\Modules\Socle\Audit\Application\DataSources;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Tests\Support\Audit\AuditTestCase;
use Tests\Support\Audit\FakeModuleSource;

final class DataRequestsTest extends AuditTestCase
{
    private FakeModuleSource $people;

    protected function setUp(): void
    {
        $this->bootAudit();
        $this->people = new FakeModuleSource();
        $sources = $this->kernel->container()->get(DataSources::class);
        $sources->registerSubject($this->people);
    }

    public function testCreationFixeLEcheanceSelonLeParametreEtJournalise(): void
    {
        $row = $this->newRequest('access');
        self::assertSame('received', $row['status']);
        self::assertSame('2026-11-07T09:00:00+01:00', $row['due_at']);
        self::assertFalse($row['overdue']);

        self::assertSame(200, $this->api('PUT', '/privacy/settings', 'alice', 't1', ['deadline_days' => 10])[0]->getStatusCode());
        self::assertSame('2026-10-18T09:00:00+01:00', $this->newRequest('portability')['due_at']);

        $this->clock->set('2026-12-01T09:00:00+01:00');
        self::assertTrue($this->api('GET', '/data-requests/' . $row['id'], 'alice', 't1')[1]['data']['overdue']);
        self::assertTrue($this->kernel->container()->get(AuditLog::class)->verify('t1')->intact);
    }

    public function testValidationEtDroits(): void
    {
        self::assertSame(422, $this->api('POST', '/data-requests', 'alice', 't1', ['kind' => 'autre', 'subject_person_id' => 'p1'])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/data-requests', 'alice', 't1', ['kind' => 'access', 'subject_person_id' => 'p1', 'tenant_id' => 't2'])[0]->getStatusCode());
        self::assertSame(403, $this->api('POST', '/data-requests', 'root', 't1', ['kind' => 'access', 'subject_person_id' => 'p1'])[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/data-requests', 'nobody', 't1')[0]->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/data-requests')->getStatusCode());
    }

    public function testAccesEtPortabiliteRestituentLesDonneesDesSources(): void
    {
        $row = $this->newRequest('access');
        self::assertSame(409, $this->api('GET', '/data-requests/' . $row['id'] . '/data', 'alice', 't1')[0]->getStatusCode());

        $this->api('POST', '/data-requests/' . $row['id'] . '/start', 'alice', 't1');
        [$response, $json] = $this->api('GET', '/data-requests/' . $row['id'] . '/data', 'alice', 't1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('p1', $json['data']['subject_person_id']);
        self::assertArrayHasKey('people', $json['data']['data']);
        self::assertArrayHasKey('audit', $json['data']['data']);

        $done = $this->api('POST', '/data-requests/' . $row['id'] . '/complete', 'alice', 't1', ['resolution' => 'Copie remise']);
        self::assertSame('processed', $done[1]['data']['status']);
        self::assertSame(409, $this->api('POST', '/data-requests/' . $row['id'] . '/start', 'alice', 't1')[0]->getStatusCode());
    }

    public function testRefusExigeUnMotif(): void
    {
        $row = $this->newRequest('rectification');
        self::assertSame(422, $this->api('POST', '/data-requests/' . $row['id'] . '/refuse', 'alice', 't1', [])[0]->getStatusCode());
        $refused = $this->api('POST', '/data-requests/' . $row['id'] . '/refuse', 'alice', 't1', ['reason' => 'Identité non vérifiée'])[1]['data'];
        self::assertSame('refused', $refused['status']);
        self::assertSame('Identité non vérifiée', $refused['refusal_reason']);
        self::assertSame(409, $this->api('POST', '/data-requests/' . $row['id'] . '/complete', 'alice', 't1')[0]->getStatusCode());
    }

    public function testEffacementExigeUneSecondePersonne(): void
    {
        $row = $this->newRequest('erasure');
        $id = $row['id'];
        $this->api('POST', '/data-requests/' . $id . '/start', 'alice', 't1');

        self::assertSame(409, $this->api('POST', '/data-requests/' . $id . '/complete', 'alice', 't1')[0]->getStatusCode(), 'Sans approbation.');
        self::assertSame(403, $this->api('POST', '/data-requests/' . $id . '/approval', 'alice', 't1', ['decision' => 'approve'])[0]->getStatusCode(), 'Le demandeur ne valide pas.');
        self::assertSame([], $this->people->anonymized);

        self::assertSame(200, $this->api('POST', '/data-requests/' . $id . '/approval', 'carl', 't1', ['decision' => 'approve'])[0]->getStatusCode());
        $done = $this->api('POST', '/data-requests/' . $id . '/complete', 'alice', 't1')[1]['data'];
        self::assertSame('processed', $done['status']);
        self::assertSame(['t1:p1'], $this->people->anonymized);
        self::assertNull($done['details']);
        self::assertTrue($this->kernel->container()->get(AuditLog::class)->verify('t1')->intact);
    }

    public function testEffacementRejeteEstRefuse(): void
    {
        $id = $this->newRequest('erasure')['id'];
        $this->api('POST', '/data-requests/' . $id . '/start', 'alice', 't1');
        $result = $this->api('POST', '/data-requests/' . $id . '/approval', 'carl', 't1', ['decision' => 'reject'])[1]['data'];
        self::assertSame('refused', $result['status']);
        self::assertSame([], $this->people->anonymized);
    }

    public function testAnonymisationPropreAAuditDepersonnaliseLesAutresDemandes(): void
    {
        $other = $this->newRequest('access', 'p1');
        $erasure = $this->newRequest('erasure', 'p1');
        $this->api('POST', '/data-requests/' . $erasure['id'] . '/start', 'alice', 't1');
        $this->api('POST', '/data-requests/' . $erasure['id'] . '/approval', 'carl', 't1', ['decision' => 'approve']);
        $this->api('POST', '/data-requests/' . $erasure['id'] . '/complete', 'alice', 't1');

        $after = $this->api('GET', '/data-requests/' . $other['id'], 'alice', 't1')[1]['data'];
        self::assertStringStartsWith('anon-', $after['subject_person_id']);
        self::assertNull($after['details']);
    }
}
