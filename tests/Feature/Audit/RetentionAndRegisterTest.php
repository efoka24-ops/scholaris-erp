<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Scholaris\Modules\Socle\Audit\Console\RetentionRunCommand;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Tests\Support\Audit\AuditTestCase;

final class RetentionAndRegisterTest extends AuditTestCase
{
    private string $oldId;
    private string $recentId;

    protected function setUp(): void
    {
        $this->bootAudit();
        $this->oldId = $this->closeRequest('p1');
        $this->clock->set('2029-12-01T09:00:00+01:00');
        $this->recentId = $this->closeRequest('p2');
    }

    private function closeRequest(string $subject): string
    {
        $id = $this->newRequest('access', $subject)['id'];
        $this->api('POST', '/data-requests/' . $id . '/start', 'alice', 't1');
        $this->api('POST', '/data-requests/' . $id . '/complete', 'alice', 't1', ['resolution' => 'Copie remise']);

        return $id;
    }

    private function runCommand(string ...$args): string
    {
        ob_start();
        $this->kernel->container()->get(RetentionRunCommand::class)->run(array_values($args));

        return (string) ob_get_clean();
    }

    public function testSimulationParDefautNeModifieRien(): void
    {
        $output = $this->runCommand();
        self::assertStringContainsString('SIMULATION', $output);
        self::assertStringContainsString('1 demande(s)', $output);
        self::assertSame('p1', $this->api('GET', '/data-requests/' . $this->oldId, 'alice', 't1')[1]['data']['subject_person_id']);
    }

    public function testApplyAnonymiseSeulementLesDonneesEchuesEtGardeLaChaine(): void
    {
        $this->runCommand('--apply');

        $old = $this->api('GET', '/data-requests/' . $this->oldId, 'alice', 't1')[1]['data'];
        self::assertStringStartsWith('anon-', $old['subject_person_id']);
        self::assertNull($old['details']);
        self::assertNull($old['resolution']);
        self::assertSame('p2', $this->api('GET', '/data-requests/' . $this->recentId, 'alice', 't1')[1]['data']['subject_person_id']);

        self::assertTrue($this->kernel->container()->get(AuditLog::class)->verify('t1')->intact);
        self::assertStringContainsString('0 demande(s)', $this->runCommand('--apply'), 'Idempotent.');
    }

    public function testOptionInconnueRefusee(): void
    {
        self::assertSame(2, $this->kernel->container()->get(RetentionRunCommand::class)->run(['--force']));
    }

    public function testRegistreDesTraitementsEnLectureSeule(): void
    {
        [$response, $json] = $this->api('GET', '/privacy/processing-register', 'alice', 't1');
        self::assertSame(200, $response->getStatusCode());
        self::assertContains('audit', array_column($json['data'], 'code'));
        self::assertSame(405, $this->api('POST', '/privacy/processing-register', 'alice', 't1', [])[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/privacy/processing-register', 'nobody', 't1')[0]->getStatusCode());
    }

    public function testParametresValides(): void
    {
        self::assertSame(422, $this->api('PUT', '/privacy/settings', 'alice', 't1', ['deadline_days' => 0])[0]->getStatusCode());
        self::assertSame(422, $this->api('PUT', '/privacy/settings', 'alice', 't1', ['inconnu' => 1])[0]->getStatusCode());
        $ok = $this->api('PUT', '/privacy/settings', 'alice', 't1', ['requests_retention_months' => 12]);
        self::assertSame(12, $ok[1]['data']['requests_retention_months']);
        self::assertSame(30, $this->api('GET', '/privacy/settings', 'bob', 't2')[1]['data']['deadline_days'] ?? 0, 'Autre client : valeurs par défaut.');
    }
}
