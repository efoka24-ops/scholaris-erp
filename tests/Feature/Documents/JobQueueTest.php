<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Modules\Socle\Documents\Application\BatchService;
use Scholaris\Modules\Socle\Documents\Application\DocumentJobService;
use Scholaris\Modules\Socle\Documents\Console\ProcessDocumentJobsCommand;
use Tests\Support\Documents\DocumentsTestCase;

final class JobQueueTest extends DocumentsTestCase
{
    private const DATA = ['academic_year' => '2026-2027', 'class_name' => '3eA'];

    protected function setUp(): void
    {
        $this->bootDocuments();
    }

    /** @return list<array<string, mixed>> */
    private function people(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $this->people->add('t1', 'x' . $i, 'Prénom' . $i, 'Nom' . $i);
            $items[] = ['subject_person_id' => 'x' . $i, 'data' => self::DATA];
        }

        return $items;
    }

    private function createBatch(int $count): string
    {
        [$response, $json] = $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => $this->people($count)]);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());

        return $json['data']['id'];
    }

    private function service(): DocumentJobService
    {
        return $this->kernel->container()->get(DocumentJobService::class);
    }

    /** @return list<array<string, mixed>> */
    private function jobs(): array
    {
        return $this->connections()->pdo()->query('SELECT * FROM document_jobs ORDER BY id')->fetchAll();
    }

    public function testLaCreationDuLotMetUneTacheEnFile(): void
    {
        $batchId = $this->createBatch(3);

        $jobs = $this->jobs();
        self::assertCount(1, $jobs);
        self::assertSame('queued', $jobs[0]['status']);
        self::assertSame($batchId, $jobs[0]['batch_id']);
        self::assertSame('t1', $jobs[0]['tenant_id']);
    }

    public function testLotDe200DocumentsTraiteParTranchesAvecMemoireBornee(): void
    {
        $batchId = $this->createBatch(200);
        gc_collect_cycles();
        $before = memory_get_usage();

        $stats = $this->service()->work(10, 50);

        self::assertSame(['jobs' => 1, 'done' => 1, 'failed' => 0, 'chunks' => 4], $stats);
        $pdo = $this->connections()->pdo();
        self::assertSame(200, (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE issued_via = 'batch'")->fetchColumn());
        self::assertSame(200, (int) $pdo->query('SELECT COUNT(DISTINCT number) FROM documents')->fetchColumn(), 'Numéros uniques.');
        self::assertSame(200, (int) $pdo->query('SELECT COUNT(DISTINCT verification_token) FROM documents')->fetchColumn(), 'Jetons de vérification uniques.');
        $batch = $this->api('GET', '/document-batches/' . $batchId, 'alice', 't1')[1]['data'];
        self::assertSame('done', $batch['status']);
        self::assertSame(100, $batch['progress']['percent']);
        self::assertSame('done', $this->jobs()[0]['status']);

        gc_collect_cycles();
        self::assertLessThan(48 * 1024 * 1024, memory_get_usage() - $before, 'La mémoire ne croît pas avec la taille du lot.');
    }

    public function testReprisePuisIdempotenceApresArretDuWorker(): void
    {
        $batchId = $this->createBatch(6);
        $pdo = $this->connections()->pdo();
        // Un premier worker a traité une tranche puis s'est arrêté : tâche « en cours », verrou ancien.
        $this->kernel->container()->get(BatchService::class)->process('t1', $batchId, 2);
        $pdo->exec("UPDATE document_jobs SET status = 'running', attempts = 1, locked_at = '2000-01-01T00:00:00+01:00'");

        $stats = $this->service()->work(10, 2);

        self::assertSame(1, $stats['done']);
        self::assertSame(6, (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE issued_via = 'batch'")->fetchColumn(), 'Aucun doublon après reprise.');
        self::assertSame(2, (int) $this->jobs()[0]['attempts']);
        self::assertSame(['jobs' => 0, 'done' => 0, 'failed' => 0, 'chunks' => 0], $this->service()->work(), 'Rien à refaire.');
    }

    public function testTacheRecenteEnCoursNEstPasReprise(): void
    {
        $this->createBatch(2);
        $this->connections()->pdo()->exec("UPDATE document_jobs SET status = 'running', attempts = 1, locked_at = '2999-01-01T00:00:00+01:00'");

        self::assertSame(0, $this->service()->work()['jobs']);
    }

    public function testTacheAbandonneeTropSouventPasseEnEchec(): void
    {
        $this->createBatch(2);
        $this->connections()->pdo()->exec("UPDATE document_jobs SET status = 'running', attempts = 3, locked_at = '2000-01-01T00:00:00+01:00'");

        $stats = $this->service()->work();

        self::assertSame(1, $stats['failed']);
        self::assertSame('failed', $this->jobs()[0]['status']);
        self::assertSame('Nombre maximal de tentatives atteint.', $this->jobs()[0]['last_error']);
    }

    public function testEchecInterneRemetEnFilePuisAbandonne(): void
    {
        $this->createBatch(1);
        $this->connections()->pdo()->exec('DELETE FROM document_batches');

        self::assertSame(0, $this->service()->work()['failed']);
        self::assertSame('queued', $this->jobs()[0]['status']);
        $this->service()->work();
        $stats = $this->service()->work();

        self::assertSame(1, $stats['failed']);
        self::assertSame('failed', $this->jobs()[0]['status']);
        self::assertNotEmpty($this->jobs()[0]['last_error']);
    }

    public function testCommandeConsole(): void
    {
        $this->createBatch(3);
        $command = $this->kernel->container()->get(ProcessDocumentJobsCommand::class);

        ob_start();
        $code = $command->run(['--max-jobs=5', '--chunk=2']);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('1 tâche(s) traitée(s), 1 terminée(s), 0 en échec, 2 tranche(s)', $out);
    }
}
