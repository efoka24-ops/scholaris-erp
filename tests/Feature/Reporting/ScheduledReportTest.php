<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Reporting\Console\RunScheduledReportsCommand;
use Tests\Support\Reporting\RecordingMessenger;
use Tests\Support\Reporting\ReportingTestCase;

/** Rapports planifiés : définition, exécution idempotente, envoi par e-mail, statistiques réglementaires, isolation. */
final class ScheduledReportTest extends ReportingTestCase
{
    private RecordingMessenger $messenger;

    protected function setUp(): void
    {
        $this->messenger = new RecordingMessenger();
        $this->bootReporting([Messenger::class => $this->messenger]);
        foreach (['mgr', 'director', 'eve'] as $user) {
            $this->access->grant($user, 'e1');
        }
        $this->access->grant('eve', 'e2');
        $this->access->grant('mallory', 'x1');
        $this->permissions->grant('mgr', 'reporting.manage');
        $this->permissions->grant('mgr', 'reporting.read');
        $this->permissions->grant('director', 'reporting.read');
        $this->permissions->grant('eve', 'reporting.manage', 'e2');
        $this->permissions->grant('mallory', 'reporting.manage');
        $this->people->add('t1', 'dir', 'Anne', 'Directrice');
        $this->people->add('t1', 'cens', 'Paul', 'Censeur');
        $this->people->add('t1', 'l1', 'Ada', 'Un', 'female');
        $this->people->add('t1', 'l2', 'Bob', 'Deux', 'male');
        $this->people->add('t1', 'l3', 'Cyd', 'Trois', 'female');
        $this->people->add('t2', 'l1', 'Autre', 'Client', 'male');
        $this->enrollments->add('t1', 'en1', 'l1', 'e1', 'c1');
        $this->enrollments->add('t1', 'en2', 'l2', 'e1', 'c1');
        $this->enrollments->add('t1', 'en3', 'l3', 'e1', 'c2');
        $this->enrollments->add('t1', 'en4', 'l4', 'e1', 'c2', 'withdrawn');
        $this->enrollments->add('t1', 'en5', 'l5', 'e2', 'c9');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    private function send(string $method, string $path, string $user = 'mgr', string $tenant = 't1', string $establishment = 'e1', ?array $body = null): array
    {
        $request = new ServerRequest($method, '/api/v1' . $path, [
            'Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment, 'Content-Type' => 'application/json',
        ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->kernel->handle($request);
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /** @return array<string, mixed> */
    private function create(array $override = []): array
    {
        [$response, $body] = $this->send('POST', '/report-schedules', body: $override + [
            'name' => 'Direction du lundi', 'report_type' => 'direction', 'frequency' => 'weekly', 'run_day' => 1, 'recipient_ids' => ['dir', 'cens'],
        ]);
        self::assertSame(201, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }

    public function testCreationCalculeLaProchaineEcheance(): void
    {
        $weekly = $this->create();
        self::assertSame('2026-10-12', $weekly['next_run_on']);
        self::assertSame(['dir', 'cens'], $weekly['recipient_ids']);
        self::assertSame('2026-10-09', $this->create(['frequency' => 'daily', 'run_day' => null, 'name' => 'Quotidien'])['next_run_on']);
        self::assertSame('2026-11-05', $this->create(['frequency' => 'monthly', 'run_day' => 5, 'name' => 'Mensuel'])['next_run_on']);
    }

    public function testValidationEtDroits(): void
    {
        $base = ['name' => 'X', 'report_type' => 'direction', 'frequency' => 'weekly', 'run_day' => 1, 'recipient_ids' => ['dir']];
        self::assertSame(403, $this->send('POST', '/report-schedules', 'director', body: $base)[0]->getStatusCode());
        self::assertSame(403, $this->send('GET', '/report-schedules', 'director')[0]->getStatusCode());
        self::assertSame(422, $this->send('POST', '/report-schedules', body: ['frequency' => 'daily'] + $base)[0]->getStatusCode(), 'quotidien sans jour');
        self::assertSame(422, $this->send('POST', '/report-schedules', body: ['run_day' => 9] + $base)[0]->getStatusCode());
        self::assertSame(422, $this->send('POST', '/report-schedules', body: ['recipient_ids' => ['inconnu']] + $base)[0]->getStatusCode());
        self::assertSame(422, $this->send('POST', '/report-schedules', body: ['report_type' => 'autre'] + $base)[0]->getStatusCode());
        self::assertSame(422, $this->send('POST', '/report-schedules', body: ['tenant_id' => 't2'] + $base)[0]->getStatusCode());
        self::assertSame(403, $this->send('POST', '/report-schedules', body: ['report_type' => 'groupe'] + $base)[0]->getStatusCode(), 'groupe exige reporting.group.read');
    }

    public function testLaCommandeEnvoieLesRapportsEchusUneSeuleFoisParJour(): void
    {
        $schedule = $this->create();
        $command = $this->container()->get(RunScheduledReportsCommand::class);

        // rien d'échu avant lundi
        self::assertSame([], $this->container()->get(\Scholaris\Modules\Socle\Reporting\Application\ScheduledReportService::class)->runDue(null, '2026-10-11'));
        self::assertSame([], $this->messenger->sent);

        $results = $this->container()->get(\Scholaris\Modules\Socle\Reporting\Application\ScheduledReportService::class)->runDue(null, '2026-10-12');
        self::assertCount(1, $results);
        self::assertSame('done', $results[0]['status']);
        self::assertCount(2, $this->messenger->sent);
        self::assertSame('scheduled_report', $this->messenger->sent[0]['template']);
        self::assertSame(['email'], $this->messenger->sent[0]['channels']);
        self::assertSame('Direction du lundi', $this->messenger->sent[0]['variables']['label']);
        self::assertSame('IPES Douala', $this->messenger->sent[0]['variables']['establishment_name']);
        self::assertStringContainsString('Inscrits : 3', (string) $this->messenger->sent[0]['variables']['details']);

        // rejouer le même jour : ni envoi ni nouvelle exécution
        self::assertSame([], $this->container()->get(\Scholaris\Modules\Socle\Reporting\Application\ScheduledReportService::class)->runDue(null, '2026-10-12'));
        self::assertCount(2, $this->messenger->sent);

        [, $shown] = $this->send('GET', '/report-schedules/' . $schedule['id']);
        self::assertSame('2026-10-19', $shown['data']['next_run_on']);
        self::assertSame('done', $shown['data']['last_run_status']);
        [, $runs] = $this->send('GET', '/report-schedules/' . $schedule['id'] . '/runs');
        self::assertCount(1, $runs['data']);
        self::assertSame(2, $runs['data'][0]['sent_count']);

        ob_start();
        self::assertSame(0, $command->run(['2026-10-19']));
        self::assertStringContainsString('1 exécuté', (string) ob_get_clean());
        self::assertSame(2, $command->run(['--bizarre']));
    }

    public function testUnDestinataireBloquePuisEnPanneNeFaitPasEchouerLesAutres(): void
    {
        $this->people->add('t1', 'bloque', 'B', 'Bloque');
        $this->people->add('t1', 'panne', 'P', 'Panne');
        $schedule = $this->create(['recipient_ids' => ['dir', 'bloque', 'panne']]);
        $result = $this->send('POST', '/report-schedules/' . $schedule['id'] . '/run');

        self::assertSame(200, $result[0]->getStatusCode());
        self::assertSame('partial', $result[1]['data']['status']);
        self::assertSame(1, $result[1]['data']['sent_count']);
        self::assertSame(2, $result[1]['data']['failed_count']);
        // l'exécution manuelle ne bloque pas une nouvelle exécution manuelle
        self::assertSame('partial', $this->send('POST', '/report-schedules/' . $schedule['id'] . '/run')[1]['data']['status']);
    }

    public function testPauseEtSuppression(): void
    {
        $schedule = $this->create();
        $paused = $this->send('PATCH', '/report-schedules/' . $schedule['id'], body: ['status' => 'paused'])[1]['data'];
        self::assertSame('paused', $paused['status']);
        self::assertSame([], $this->container()->get(\Scholaris\Modules\Socle\Reporting\Application\ScheduledReportService::class)->runDue(null, '2026-10-20'));
        self::assertSame(200, $this->send('DELETE', '/report-schedules/' . $schedule['id'])[0]->getStatusCode());
        self::assertSame(404, $this->send('GET', '/report-schedules/' . $schedule['id'])[0]->getStatusCode());
    }

    public function testLeRapportReglementairePasseParLeMemeCircuit(): void
    {
        $schedule = $this->create(['report_type' => 'regulatory', 'name' => 'Effectifs']);
        $this->send('POST', '/report-schedules/' . $schedule['id'] . '/run');
        self::assertStringContainsString('Inscrits actifs : 3', (string) $this->messenger->sent[0]['variables']['details']);
    }

    public function testStatistiquesReglementaires(): void
    {
        [$response, $body] = $this->send('GET', '/regulatory-statistics', 'director');
        self::assertSame(200, $response->getStatusCode(), json_encode($body));
        $data = $body['data'];
        self::assertSame(3, $data['total_enrolled']);
        self::assertSame([['academic_year_id' => 'ay1', 'enrolled' => 3]], $data['by_academic_year']['items']);
        self::assertSame([['structure_id' => 'c1', 'enrolled' => 2], ['structure_id' => 'c2', 'enrolled' => 1]], $data['by_structure']['items']);
        self::assertTrue($data['by_sex']['available']);
        self::assertSame([['sex' => 'female', 'enrolled' => 2], ['sex' => 'male', 'enrolled' => 1]], $data['by_sex']['items']);
        self::assertFalse($data['by_level']['available']);
        self::assertNotSame('', $data['by_level']['reason']);
        self::assertSame(403, $this->send('GET', '/regulatory-statistics', 'eve')[0]->getStatusCode());
    }

    public function testIsolationInterClientsEtInterEtablissements(): void
    {
        $schedule = $this->create();
        // autre établissement du même client
        self::assertSame(404, $this->send('GET', '/report-schedules/' . $schedule['id'], 'eve', 't1', 'e2')[0]->getStatusCode());
        self::assertSame(404, $this->send('POST', '/report-schedules/' . $schedule['id'] . '/run', 'eve', 't1', 'e2')[0]->getStatusCode());
        self::assertSame([], $this->send('GET', '/report-schedules', 'eve', 't1', 'e2')[1]['data']);
        // autre client
        self::assertContains($this->send('GET', '/report-schedules/' . $schedule['id'], 'mallory', 't2', 'x1')[0]->getStatusCode(), [403, 404]);
        self::assertSame([], $this->send('GET', '/report-schedules', 'mallory', 't2', 'x1')[1]['data']);
        self::assertSame(404, $this->send('DELETE', '/report-schedules/' . $schedule['id'], 'mallory', 't2', 'x1')[0]->getStatusCode());
        // un destinataire d'un autre client est refusé
        $this->people->add('t2', 'etranger', 'E', 'Tranger');
        self::assertSame(422, $this->send('POST', '/report-schedules', body: [
            'name' => 'X', 'report_type' => 'direction', 'frequency' => 'daily', 'recipient_ids' => ['etranger'],
        ])[0]->getStatusCode());
        // un rapport reste exécuté dans son seul client
        $this->container()->get(\Scholaris\Modules\Socle\Reporting\Application\ScheduledReportService::class)->runDue('t2', '2026-10-12');
        self::assertSame([], $this->messenger->sent);
    }
}
