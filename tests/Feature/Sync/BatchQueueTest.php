<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use Scholaris\Modules\Socle\Sync\Console\ProcessBatchesCommand;
use Tests\Support\Sync\SyncTestCase;

/**
 * Grands lots : au-delà du seuil de traitement immédiat, le lot est mis en file (202) et traité par `sync:process` (cron,
 * sans Redis). La commande reprend les lots restés « en cours » trop longtemps et ne traite jamais un lot deux fois.
 */
final class BatchQueueTest extends SyncTestCase
{
    /** @var array{id: string, key: string} */
    private array $device;

    protected function setUp(): void
    {
        $this->bootSync(['sync.batch.inline_max_items' => 1]);
        $this->device = $this->registerDevice();
    }

    /** @return array{0: string, 1: list<array<string, mixed>>} */
    private function queueTwoItems(string $batchId = 'LOT-SYN-0100'): array
    {
        $items = [$this->item(['key' => 'seance-1', 'value' => 'present']), $this->item(['key' => 'seance-2', 'value' => 'absent'])];
        $response = $this->post($items, $this->device['id'], $this->device['key'], ['batch_id' => $batchId]);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());

        return [$batchId, $items];
    }

    private function runCommand(string ...$args): string
    {
        ob_start();
        $code = $this->container()->get(ProcessBatchesCommand::class)->run(array_values($args));
        $output = (string) ob_get_clean();
        self::assertSame(0, $code, $output);

        return $output;
    }

    public function testUnGrandLotEstMisEnFileSansEtreTraite(): void
    {
        [$batchId] = $this->queueTwoItems();

        [, $json] = $this->api('GET', '/sync/batches/' . $batchId, 'teacher');

        self::assertSame('queued', $json['data']['status']);
        self::assertSame(['applied' => 0, 'duplicate' => 0, 'conflict' => 0, 'rejected' => 0, 'pending' => 2], $json['data']['counts']);
        self::assertSame('pending', $json['data']['items'][0]['status']);
        self::assertSame([], $this->handler->calls);
    }

    public function testLaReponseDeMiseEnFileDecritLeLot(): void
    {
        $items = [$this->item(['key' => 'a', 'value' => 1]), $this->item(['key' => 'b', 'value' => 2])];

        $response = $this->post($items, $this->device['id'], $this->device['key'], ['batch_id' => 'LOT-SYN-0101']);
        $json = $this->decode($response);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('queued', $json['data']['status']);
        self::assertSame(2, $json['data']['counts']['pending']);
    }

    public function testLaCommandeTraiteLaFileEtLeResultatSeLitEnsuite(): void
    {
        [$batchId] = $this->queueTwoItems();

        $output = $this->runCommand();

        self::assertStringContainsString('1 lot(s) traité(s)', $output);
        self::assertCount(2, $this->handler->calls);
        self::assertSame('teacher', $this->handler->calls[0]['context']->principal->userId, 'Le lot est traité au nom de la personne qui l\'a envoyé.');
        self::assertSame('e1', $this->handler->calls[0]['context']->establishmentId);
        [, $json] = $this->api('GET', '/sync/batches/' . $batchId, 'teacher');
        self::assertSame('processed', $json['data']['status']);
        self::assertSame(['applied', 'applied'], array_column($json['data']['items'], 'status'));
        self::assertNull($this->connections()->pdo()->query('SELECT payload FROM sync_items LIMIT 1')->fetchColumn(), 'Charge utile effacée après traitement.');
    }

    public function testUnSecondPassageNeRetraitePasLeLot(): void
    {
        $this->queueTwoItems();
        $this->runCommand();

        $output = $this->runCommand();

        self::assertStringContainsString('0 lot(s) traité(s)', $output);
        self::assertCount(2, $this->handler->calls);
    }

    public function testLaLimiteDeLotsParPassageEstRespectee(): void
    {
        $this->queueTwoItems('LOT-SYN-0110');
        $this->queueTwoItems('LOT-SYN-0111');

        $first = $this->runCommand('--limit=1');
        $second = $this->runCommand('--limit=1');

        self::assertStringContainsString('1 lot(s) traité(s)', $first);
        self::assertStringContainsString('1 lot(s) traité(s)', $second);
        self::assertCount(4, $this->handler->calls);
    }

    public function testUnLotEnCoursDepuisTropLongtempsEstRepris(): void
    {
        [$batchId] = $this->queueTwoItems();
        $this->connections()->pdo()->prepare("UPDATE sync_batches SET status = 'processing', locked_at = ? WHERE batch_id = ?")->execute(['2026-10-08T08:00:00+01:00', $batchId]);

        $stale = $this->runCommand();
        self::assertStringContainsString('1 lot(s) traité(s)', $stale, 'Verrou vieux de plus de 10 minutes : le lot est repris.');

        $this->queueTwoItems('LOT-SYN-0120');
        $this->connections()->pdo()->prepare("UPDATE sync_batches SET status = 'processing', locked_at = ? WHERE batch_id = ?")->execute(['2026-10-08T08:58:00+01:00', 'LOT-SYN-0120']);
        self::assertStringContainsString('0 lot(s) traité(s)', $this->runCommand(), 'Verrou récent : un autre processus traite ce lot.');
    }

    public function testLaFileSeRejouePourUnElementDejaAppliqueSansLeRappliquer(): void
    {
        [$batchId, $items] = $this->queueTwoItems();
        $this->post([$items[0]], $this->device['id'], $this->device['key']);
        $before = count($this->handler->calls);
        self::assertSame(1, $before, 'Un lot d\'un seul élément est traité tout de suite.');

        $this->runCommand();

        self::assertCount(2, $this->handler->calls, 'Seul le second élément du lot en file est traité.');
        [, $json] = $this->api('GET', '/sync/batches/' . $batchId, 'teacher');
        self::assertSame(['duplicate', 'applied'], array_column($json['data']['items'], 'status'));
    }

    public function testLeRejeuDUnLotEnFileRepondEnAttente(): void
    {
        [$batchId, $items] = $this->queueTwoItems();

        $response = $this->post($items, $this->device['id'], $this->device['key'], ['batch_id' => $batchId]);
        $json = $this->decode($response);

        self::assertSame(202, $response->getStatusCode());
        self::assertTrue($json['data']['replayed']);
        self::assertSame('queued', $json['data']['status']);
    }

    public function testLaCommandeEstDeclareeDansLeConteneur(): void
    {
        self::assertInstanceOf(ProcessBatchesCommand::class, $this->container()->get('console.command.sync:process'));
        self::assertSame('sync:process', ProcessBatchesCommand::NAME);
    }

    public function testLaCommandeRefuseUnArgumentInconnu(): void
    {
        ob_start();
        $code = $this->container()->get(ProcessBatchesCommand::class)->run(['--limite=3']);
        ob_end_clean();

        self::assertSame(2, $code);
    }
}
