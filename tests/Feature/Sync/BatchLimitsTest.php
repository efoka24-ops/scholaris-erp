<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use Tests\Support\Sync\SyncTestCase;

/**
 * Bornes d'un lot (paramétrées, valeurs par défaut « à confirmer ») : nombre d'éléments, taille compressée, taille décompressée
 * (bombe de décompression), lignes par élément, forme du corps.
 */
final class BatchLimitsTest extends SyncTestCase
{
    /** @var array{id: string, key: string} */
    private array $device;

    protected function setUp(): void
    {
        $this->bootSync();
        $this->device = $this->registerDevice();
    }

    public function testLesLimitesParDefautReprennentCellesDeLAppel(): void
    {
        $settings = $this->container()->get(\Scholaris\Modules\Socle\Sync\Application\SyncSettings::class);

        self::assertSame(50, $settings->maxItems('t1', 'e1'), '50 séances par lot (observé pour l\'appel, à confirmer).');
        self::assertSame(500, $settings->maxRowsPerItem('t1', 'e1'), '500 lignes par séance (observé pour l\'appel, à confirmer).');
        self::assertSame(262144, $settings->maxCompressedBytes('t1', 'e1'));
        self::assertSame(2097152, $settings->maxUncompressedBytes('t1', 'e1'));
        self::assertSame(25, $settings->inlineMaxItems('t1', 'e1'));
    }

    public function testLesParametresSontBornesEtRepliesSurLeDefautSiInvalides(): void
    {
        $this->settings->values = ['sync.batch.max_items' => '12', 'sync.batch.max_rows_per_item' => 'beaucoup', 'sync.batch.max_compressed_bytes' => -5, 'sync.batch.max_uncompressed_bytes' => 99999999999];
        $settings = $this->container()->get(\Scholaris\Modules\Socle\Sync\Application\SyncSettings::class);

        self::assertSame(12, $settings->maxItems('t1', 'e1'));
        self::assertSame(500, $settings->maxRowsPerItem('t1', 'e1'));
        self::assertSame(262144, $settings->maxCompressedBytes('t1', 'e1'));
        self::assertSame(16777216, $settings->maxUncompressedBytes('t1', 'e1'), 'Plafond absolu : le paramètre ne peut pas lever toute borne.');
    }

    public function testUnLotAuDelaDuNombreDElementsEstRefuse(): void
    {
        $this->settings->values['sync.batch.max_items'] = 3;
        $items = array_map(fn (int $i): array => $this->item(['key' => 'k' . $i, 'value' => $i]), range(1, 4));

        $response = $this->post($items, $this->device['id'], $this->device['key']);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('items', $this->decode($response)['errors']);
        self::assertSame([], $this->handler->calls);
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM sync_batches')->fetchColumn());
    }

    public function testUnLotAuNombreMaximalEstAccepte(): void
    {
        $this->settings->values['sync.batch.max_items'] = 3;
        $items = array_map(fn (int $i): array => $this->item(['key' => 'k' . $i, 'value' => $i]), range(1, 3));

        self::assertSame(200, $this->post($items, $this->device['id'], $this->device['key'])->getStatusCode());
    }

    public function testUnCorpsCompresseTropGrosEstRefuse413(): void
    {
        $this->settings->values['sync.batch.max_compressed_bytes'] = 400;
        $noise = array_map(static fn (): string => bin2hex(random_bytes(40)), range(1, 20));

        $response = $this->post([$this->item(['key' => 'a', 'value' => $noise])], $this->device['id'], $this->device['key']);

        self::assertSame(413, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->handler->calls);
    }

    public function testUneBombeDeDecompressionEstRefuse413(): void
    {
        $this->settings->values['sync.batch.max_uncompressed_bytes'] = 4096;
        $json = json_encode(['items' => [$this->item(['key' => 'a', 'value' => str_repeat('A', 2_000_000)])]], JSON_THROW_ON_ERROR);
        $raw = (string) gzencode($json, 9);
        self::assertLessThan(10_000, strlen($raw), 'Le corps compressé reste petit : c\'est bien une bombe.');

        $response = $this->post([], $this->device['id'], $this->device['key'], ['raw' => $raw]);

        self::assertSame(413, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->handler->calls);
    }

    public function testUnCorpsNonCompresseOuIllisibleEstRefuse(): void
    {
        $plain = $this->post([$this->item(['key' => 'a', 'value' => 1])], $this->device['id'], $this->device['key'], ['gzip' => false]);
        $notGzip = $this->post([], $this->device['id'], $this->device['key'], ['raw' => 'ceci n\'est pas du gzip']);
        $notJson = $this->post([], $this->device['id'], $this->device['key'], ['raw' => (string) gzencode('{"items": [')]);
        $notObject = $this->post([], $this->device['id'], $this->device['key'], ['raw' => (string) gzencode('[1,2]')]);

        self::assertSame(415, $plain->getStatusCode(), 'Content-Encoding: gzip est obligatoire.');
        self::assertSame(400, $notGzip->getStatusCode());
        self::assertSame(400, $notJson->getStatusCode());
        self::assertSame(400, $notObject->getStatusCode());
        self::assertSame([], $this->handler->calls);
    }

    public function testLaFormeDesElementsEstValidee(): void
    {
        $cases = [
            'items manquant' => [[], '{}', 'items'],
            'items vide' => [[], '{"items":[]}', 'items'],
            'items pas une liste' => [[], '{"items":{"a":1}}', 'items'],
            'champ inconnu du lot' => [[], '{"items":[{"kind":"test.record","client_uuid":"CLIENT-0001","payload":{}}],"tenant_id":"t2"}', 'tenant_id'],
            'champ inconnu de l\'élément' => [[], '{"items":[{"kind":"test.record","client_uuid":"CLIENT-0001","payload":{},"establishment_id":"e2"}]}', 'items.0.establishment_id'],
            'kind manquant' => [[], '{"items":[{"client_uuid":"CLIENT-0001","payload":{}}]}', 'items.0.kind'],
            'client_uuid invalide' => [[], '{"items":[{"kind":"test.record","client_uuid":"a b","payload":{}}]}', 'items.0.client_uuid'],
            'payload pas un objet' => [[], '{"items":[{"kind":"test.record","client_uuid":"CLIENT-0001","payload":[1,2]}]}', 'items.0.payload'],
            'payload manquant' => [[], '{"items":[{"kind":"test.record","client_uuid":"CLIENT-0001"}]}', 'items.0.payload'],
        ];

        foreach ($cases as $label => [, $json, $field]) {
            $response = $this->post([], $this->device['id'], $this->device['key'], ['raw' => (string) gzencode($json)]);

            self::assertSame(422, $response->getStatusCode(), $label . ' : ' . $response->getBody());
            self::assertArrayHasKey($field, $this->decode($response)['errors'], $label);
        }
        self::assertSame([], $this->handler->calls);
    }

    public function testUnElementAuDelaDuNombreDeLignesEstRejeteSeul(): void
    {
        $this->settings->values['sync.batch.max_rows_per_item'] = 3;
        $tooMany = $this->item(['key' => 'a', 'value' => 1, 'records' => [1, 2, 3, 4]]);
        $fine = $this->item(['key' => 'b', 'value' => 1, 'records' => [1, 2, 3]]);

        $json = $this->decode($this->post([$tooMany, $fine], $this->device['id'], $this->device['key']));

        self::assertSame(['rejected', 'applied'], array_column($json['data']['items'], 'status'));
        self::assertSame(422, $json['data']['items'][0]['code']);
        self::assertCount(1, $this->handler->calls);
    }
}
