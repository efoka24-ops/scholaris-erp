<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use Tests\Support\Sync\SyncTestCase;

/**
 * Isolation : un appareil, un lot et un élément n'existent que pour leur client ET leur établissement ; rien ne fuit d'un
 * client à l'autre, ni par l'API, ni par les rejeux, ni par la file.
 */
final class SyncIsolationTest extends SyncTestCase
{
    protected function setUp(): void
    {
        $this->bootSync(['sync.batch.inline_max_items' => 1]);
    }

    public function testLAppareilDUnClientNeSertPasChezUnAutre(): void
    {
        $device = $this->registerDevice('director', 't1', 'e1');

        $response = $this->post([$this->item(['key' => 'a', 'value' => 1])], $device['id'], $device['key'], ['user' => 'other', 'tenant' => 't2', 'establishment' => 'x1']);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->handler->calls);
    }

    public function testLAppareilDUnEtablissementNeSertPasDansUnAutreDuMemeClient(): void
    {
        $device = $this->registerDevice('director', 't1', 'e1');

        $response = $this->post([$this->item(['key' => 'a', 'value' => 1])], $device['id'], $device['key'], ['establishment' => 'e2']);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->handler->calls);
    }

    public function testLesAppareilsEtLeursRevocationsSontCloisonnes(): void
    {
        $mine = $this->registerDevice('director', 't1', 'e1');
        $theirs = $this->registerDevice('other', 't2', 'x1');
        $other = $this->registerDevice('director', 't1', 'e2');

        [, $listT1] = $this->api('GET', '/sync/devices', 'director');
        [, $listT2] = $this->api('GET', '/sync/devices', 'other', null, 't2', 'x1');
        [$crossTenant] = $this->api('POST', '/sync/devices/' . $theirs['id'] . '/revoke', 'director');
        [$crossEstablishment] = $this->api('POST', '/sync/devices/' . $other['id'] . '/revoke', 'director');

        self::assertSame([$mine['id']], array_column($listT1['data'], 'id'));
        self::assertSame([$theirs['id']], array_column($listT2['data'], 'id'));
        self::assertSame(404, $crossTenant->getStatusCode());
        self::assertSame(404, $crossEstablishment->getStatusCode());
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM sync_devices WHERE revoked_at IS NOT NULL')->fetchColumn());
    }

    public function testLeMemeIdentifiantDeLotEtLeMemeElementSontIndependantsEntreClients(): void
    {
        $mine = $this->registerDevice('director', 't1', 'e1');
        $theirs = $this->registerDevice('other', 't2', 'x1');
        $uuid = self::ulid();
        $item = $this->item(['key' => 'seance-1', 'value' => 'mien'], $uuid);
        $theirItem = $this->item(['key' => 'seance-t2', 'value' => 'leur'], $uuid);

        $first = $this->decode($this->post([$item], $mine['id'], $mine['key'], ['batch_id' => 'LOT-SYN-0200']));
        $second = $this->decode($this->post([$theirItem], $theirs['id'], $theirs['key'], ['batch_id' => 'LOT-SYN-0200', 'user' => 'other', 'tenant' => 't2', 'establishment' => 'x1']));

        self::assertSame('applied', $first['data']['items'][0]['status']);
        self::assertFalse($second['data']['replayed'], 'Le lot de l\'autre client n\'est pas un rejeu.');
        self::assertSame('applied', $second['data']['items'][0]['status'], 'L\'élément de l\'autre client n\'est pas un doublon.');
        self::assertSame(2, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM sync_batches')->fetchColumn());
        $tenants = $this->connections()->pdo()->query('SELECT DISTINCT tenant_id FROM sync_items ORDER BY tenant_id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['t1', 't2'], $tenants);
    }

    public function testUnLotNEstLisibleQueDeSonClientEtDeSonEtablissement(): void
    {
        $mine = $this->registerDevice('director', 't1', 'e1');
        $this->post([$this->item(['key' => 'a', 'value' => 1])], $mine['id'], $mine['key'], ['batch_id' => 'LOT-SYN-0201']);

        self::assertSame(200, $this->api('GET', '/sync/batches/LOT-SYN-0201', 'teacher')[0]->getStatusCode());
        self::assertSame(404, $this->api('GET', '/sync/batches/LOT-SYN-0201', 'teacher', null, 't1', 'e2')[0]->getStatusCode());
        self::assertSame(404, $this->api('GET', '/sync/batches/LOT-SYN-0201', 'other', null, 't2', 'x1')[0]->getStatusCode());
    }

    public function testLaFileTraiteChaqueLotDansSonClientEtSonEtablissement(): void
    {
        $mine = $this->registerDevice('director', 't1', 'e1');
        $theirs = $this->registerDevice('other', 't2', 'x1');
        $two = static fn (callable $make): array => [$make('a'), $make('b')];
        $make = fn (string $key): array => $this->item(['key' => $key, 'value' => 1]);
        $this->post($two($make), $mine['id'], $mine['key']);
        $this->post($two($make), $theirs['id'], $theirs['key'], ['user' => 'other', 'tenant' => 't2', 'establishment' => 'x1']);

        ob_start();
        $this->container()->get(\Scholaris\Modules\Socle\Sync\Console\ProcessBatchesCommand::class)->run([]);
        ob_end_clean();

        $seen = array_map(static fn (array $call): string => $call['context']->tenantId() . '/' . $call['context']->establishmentId, $this->handler->calls);
        self::assertSame(['t1/e1', 't1/e1', 't2/x1', 't2/x1'], $seen);
        self::assertFalse($this->container()->get(\Scholaris\Core\Tenancy\TenantContext::class)->hasTenant(), 'Le contexte de client est nettoyé après la commande.');
    }

    public function testToutesLesTablesPortentLeClient(): void
    {
        foreach (['sync_devices', 'sync_batches', 'sync_items'] as $table) {
            $columns = array_column($this->connections()->pdo()->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
            self::assertContains('tenant_id', $columns, $table);
            self::assertContains('establishment_id', $columns, $table);
        }
    }
}
