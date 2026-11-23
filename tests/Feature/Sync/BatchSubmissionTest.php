<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use Tests\Support\Sync\SyncTestCase;

/**
 * POST /sync/batches : lot gzip signé par la clé de l'appareil (HMAC-SHA256), Idempotency-Key = identifiant du lot,
 * éléments {kind, client_uuid, payload} répartis vers `sync.handlers.<kind>`, résultat par élément.
 */
final class BatchSubmissionTest extends SyncTestCase
{
    /** @var array{id: string, key: string} */
    private array $device;

    protected function setUp(): void
    {
        $this->bootSync();
        $this->device = $this->registerDevice();
    }

    public function testUnLotGzipSigneEstApplique(): void
    {
        $first = $this->item(['key' => 'seance-1', 'value' => 'present']);
        $second = $this->item(['key' => 'seance-2', 'value' => 'absent']);

        $response = $this->post([$first, $second], $this->device['id'], $this->device['key'], ['batch_id' => 'LOT-SYN-0001']);
        $json = $this->decode($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('LOT-SYN-0001', $json['data']['id']);
        self::assertSame('processed', $json['data']['status']);
        self::assertFalse($json['data']['replayed']);
        self::assertSame(['applied' => 2, 'duplicate' => 0, 'conflict' => 0, 'rejected' => 0, 'pending' => 0], $json['data']['counts']);
        self::assertSame('applied', $json['data']['items'][0]['status']);
        self::assertSame(200, $json['data']['items'][0]['code']);
        self::assertSame($first['client_uuid'], $json['data']['items'][0]['client_uuid']);
        self::assertSame(['key' => 'seance-1', 'version' => 1], $json['data']['items'][0]['data']);

        self::assertCount(2, $this->handler->calls);
        $context = $this->handler->calls[0]['context'];
        self::assertSame('t1', $context->tenantId());
        self::assertSame('e1', $context->establishmentId);
        self::assertSame('teacher', $context->principal->userId);
        self::assertSame($this->device['id'], $context->deviceId);
        self::assertSame('LOT-SYN-0001', $context->batchId);
        self::assertSame(['key' => 'seance-1', 'value' => 'present'], $this->handler->calls[0]['item']->payload);
        self::assertSame('present', $this->handler->store['seance-1']['value']);
    }

    public function testLaSignatureAcceptePrefixeSha256EtMajuscules(): void
    {
        $batchId = self::ulid();
        $raw = (string) gzencode(json_encode(['items' => [$this->item(['key' => 'k', 'value' => 'v'])]], JSON_THROW_ON_ERROR));
        $hex = hash_hmac('sha256', $this->device['id'] . "\n" . $batchId . "\n" . $raw, $this->device['key']);

        $response = $this->post([], $this->device['id'], $this->device['key'], ['batch_id' => $batchId, 'raw' => $raw, 'signature' => 'sha256=' . strtoupper($hex)]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testLaChargeUtileEstEffaceeApresTraitement(): void
    {
        $this->post([$this->item(['key' => 'seance-1', 'value' => 'SYN-ELEVE-NOM'])], $this->device['id'], $this->device['key']);

        $rows = $this->connections()->pdo()->query('SELECT payload, status FROM sync_items')->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('applied', $rows[0]['status']);
        self::assertNull($rows[0]['payload'], 'Minimisation : la charge utile n\'est pas conservée une fois traitée.');
    }

    public function testUnLotRejoueEstDuplicateEtNEstPasReapplique(): void
    {
        $items = [$this->item(['key' => 'seance-1', 'value' => 'present']), $this->item(['key' => 'seance-2', 'value' => 'absent'])];
        $options = ['batch_id' => 'LOT-SYN-0002'];
        $this->post($items, $this->device['id'], $this->device['key'], $options);

        $response = $this->post($items, $this->device['id'], $this->device['key'], $options);
        $json = $this->decode($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($json['data']['replayed']);
        self::assertSame(['applied' => 0, 'duplicate' => 2, 'conflict' => 0, 'rejected' => 0, 'pending' => 0], $json['data']['counts']);
        self::assertSame('duplicate', $json['data']['items'][0]['status']);
        self::assertSame('applied', $json['data']['items'][0]['original_status']);
        self::assertCount(2, $this->handler->calls, 'Le gestionnaire n\'est pas rappelé pour un lot rejoué.');
        self::assertSame(1, $this->handler->store['seance-1']['version']);
        self::assertSame(1, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM sync_batches')->fetchColumn());
    }

    public function testUnElementDejaAppliqueDansUnAutreLotEstDuplicate(): void
    {
        $item = $this->item(['key' => 'seance-1', 'value' => 'present']);
        $this->post([$item], $this->device['id'], $this->device['key']);

        $response = $this->post([$item], $this->device['id'], $this->device['key']);
        $json = $this->decode($response);

        self::assertSame('processed', $json['data']['status']);
        self::assertFalse($json['data']['replayed']);
        self::assertSame('duplicate', $json['data']['items'][0]['status']);
        self::assertCount(1, $this->handler->calls);
    }

    public function testLeMemeIdentifiantDeLotAvecUnAutreCorpsEstRefuse(): void
    {
        $this->post([$this->item(['key' => 'a', 'value' => 1])], $this->device['id'], $this->device['key'], ['batch_id' => 'LOT-SYN-0003']);

        $response = $this->post([$this->item(['key' => 'b', 'value' => 2])], $this->device['id'], $this->device['key'], ['batch_id' => 'LOT-SYN-0003']);

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(1, $this->handler->calls);
    }

    public function testUneSignatureFausseEstRefusee401(): void
    {
        $items = [$this->item(['key' => 'seance-1', 'value' => 'present'])];

        $wrongKey = $this->post($items, $this->device['id'], $this->device['key'], ['key' => str_repeat('0', 64)]);
        $missing = $this->post($items, $this->device['id'], $this->device['key'], ['signature' => null]);
        $malformed = $this->post($items, $this->device['id'], $this->device['key'], ['signature' => 'pas-un-hmac']);
        $otherBatch = $this->post($items, $this->device['id'], $this->device['key'], [
            'batch_id' => 'LOT-SYN-0004',
            'signature' => hash_hmac('sha256', $this->device['id'] . "\nAUTRE-LOT\n" . gzencode(json_encode(['items' => $items], JSON_THROW_ON_ERROR)), $this->device['key']),
        ]);

        foreach ([$wrongKey, $missing, $malformed, $otherBatch] as $response) {
            self::assertSame(401, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        }
        self::assertSame([], $this->handler->calls);
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM sync_batches')->fetchColumn());
    }

    public function testUnCorpsAlterePosterieurementALaSignatureEstRefuse(): void
    {
        $batchId = self::ulid();
        $signed = (string) gzencode(json_encode(['items' => [$this->item(['key' => 'a', 'value' => 'ok'])]], JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $this->device['id'] . "\n" . $batchId . "\n" . $signed, $this->device['key']);
        $tampered = (string) gzencode(json_encode(['items' => [$this->item(['key' => 'a', 'value' => 'pirate'])]], JSON_THROW_ON_ERROR));

        $response = $this->post([], $this->device['id'], $this->device['key'], ['batch_id' => $batchId, 'raw' => $tampered, 'signature' => $signature]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->handler->calls);
    }

    public function testUnAppareilRevoqueEstRefuse401(): void
    {
        $this->post([$this->item(['key' => 'a', 'value' => 1])], $this->device['id'], $this->device['key']);
        $this->api('POST', '/sync/devices/' . $this->device['id'] . '/revoke', 'director');

        $response = $this->post([$this->item(['key' => 'b', 'value' => 2])], $this->device['id'], $this->device['key']);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->handler->calls);
    }

    public function testUnAppareilInconnuEstRefuse401AvecLeMemeMessage(): void
    {
        $items = [$this->item(['key' => 'a', 'value' => 1])];

        $unknown = $this->post($items, self::ulid(), $this->device['key']);
        $badSignature = $this->post($items, $this->device['id'], $this->device['key'], ['key' => str_repeat('1', 64)]);

        self::assertSame(401, $unknown->getStatusCode());
        self::assertSame($this->decode($badSignature)['detail'], $this->decode($unknown)['detail'], 'Aucune indication sur l\'existence de l\'appareil.');
    }

    public function testUnConflitEstRenduParElementSansEcraserLaVersionDuServeur(): void
    {
        $this->post([$this->item(['key' => 'seance-1', 'value' => 'serveur'])], $this->device['id'], $this->device['key']);

        $conflicting = $this->item(['key' => 'seance-1', 'value' => 'appareil-perime', 'base_version' => 0]);
        $fresh = $this->item(['key' => 'seance-2', 'value' => 'ok']);
        $response = $this->post([$conflicting, $fresh], $this->device['id'], $this->device['key']);
        $json = $this->decode($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('conflict', $json['data']['items'][0]['status']);
        self::assertSame(409, $json['data']['items'][0]['code']);
        self::assertSame(['key' => 'seance-1', 'version' => 1, 'value' => 'serveur'], $json['data']['items'][0]['data']['server']);
        self::assertSame('applied', $json['data']['items'][1]['status'], 'Un conflit n\'arrête pas le reste du lot.');
        self::assertSame(['applied' => 1, 'duplicate' => 0, 'conflict' => 1, 'rejected' => 0, 'pending' => 0], $json['data']['counts']);
        self::assertSame('serveur', $this->handler->store['seance-1']['value'], 'La version du serveur n\'est jamais écrasée.');
        self::assertSame(1, $this->handler->store['seance-1']['version']);
        self::assertSame('conflict', $this->connections()->pdo()->query("SELECT status FROM sync_items WHERE client_uuid = '" . $conflicting['client_uuid'] . "'")->fetchColumn());
    }

    public function testUnElementEnConflitPeutEtreRenvoyeApresResolution(): void
    {
        $this->post([$this->item(['key' => 'seance-1', 'value' => 'serveur'])], $this->device['id'], $this->device['key']);
        $uuid = self::ulid();
        $this->post([$this->item(['key' => 'seance-1', 'value' => 'appareil', 'base_version' => 0], $uuid)], $this->device['id'], $this->device['key']);

        $json = $this->decode($this->post([$this->item(['key' => 'seance-1', 'value' => 'appareil', 'base_version' => 1], $uuid)], $this->device['id'], $this->device['key']));

        self::assertSame('applied', $json['data']['items'][0]['status'], 'Seuls les éléments appliqués sont des doublons.');
        self::assertSame('appareil', $this->handler->store['seance-1']['value']);
    }

    public function testUnGestionnaireAbsentRejetteSeulementSonElement(): void
    {
        $unknown = $this->item(['x' => 1], null, 'public.inconnu');
        $known = $this->item(['key' => 'seance-1', 'value' => 'present']);

        $response = $this->post([$unknown, $known], $this->device['id'], $this->device['key']);
        $json = $this->decode($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('rejected', $json['data']['items'][0]['status']);
        self::assertSame(422, $json['data']['items'][0]['code']);
        self::assertStringContainsString('public.inconnu', $json['data']['items'][0]['message']);
        self::assertSame('applied', $json['data']['items'][1]['status']);
        self::assertSame(['applied' => 1, 'duplicate' => 0, 'conflict' => 0, 'rejected' => 1, 'pending' => 0], $json['data']['counts']);
    }

    public function testUnTypeNeServantPasDeCleDeConteneurEstRefuseSansRechercheDansLeConteneur(): void
    {
        $items = [
            $this->item(['x' => 1], null, '..\\..\\Evil'),
            $this->item(['x' => 1], null, 'Scholaris\\Core\\Kernel'),
            $this->item(['x' => 1], null, 'a..b'),
        ];

        $response = $this->post($items, $this->device['id'], $this->device['key']);

        self::assertSame(422, $response->getStatusCode(), 'Le type est validé (minuscules, chiffres, _ et points) avant tout usage.');
        self::assertArrayHasKey('items.0.kind', $this->decode($response)['errors']);
    }

    public function testUnGestionnaireQuiRefuseOuQuiPlanteRejetteSonElementSansFuite(): void
    {
        $forbid = $this->item(['forbid' => true]);
        $reject = $this->item(['reject' => true]);
        $boom = $this->item(['boom' => true]);
        $ok = $this->item(['key' => 'seance-1', 'value' => 'present']);

        $response = $this->post([$forbid, $reject, $boom, $ok], $this->device['id'], $this->device['key']);
        $json = $this->decode($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['rejected', 'rejected', 'rejected', 'applied'], array_column($json['data']['items'], 'status'));
        self::assertSame([403, 422, 500, 200], array_column($json['data']['items'], 'code'));
        self::assertSame(['value' => ['Valeur interdite.']], $json['data']['items'][1]['data']['errors']);
        self::assertStringNotContainsString('SYN-NOM', (string) $response->getBody(), 'Le message d\'une exception brute n\'est jamais rendu.');
        $log = (string) @file_get_contents(dirname(__DIR__, 3) . '/storage/logs/test.log');
        self::assertStringNotContainsString('détail interne secret SYN-NOM', $log, 'Ni charge utile ni message brut dans les journaux.');
    }

    public function testUnMemeElementRepeteDansUnLotEstApplique1Fois(): void
    {
        $item = $this->item(['key' => 'seance-1', 'value' => 'present']);

        $json = $this->decode($this->post([$item, $item], $this->device['id'], $this->device['key']));

        self::assertSame(['applied', 'duplicate'], array_column($json['data']['items'], 'status'));
        self::assertCount(1, $this->handler->calls);
    }

    public function testLaRouteExigeLeDroitDeSoumettreUnLot(): void
    {
        $response = $this->post([$this->item(['key' => 'a', 'value' => 1])], $this->device['id'], $this->device['key'], ['user' => 'nobody']);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->handler->calls);
    }

    public function testLaRouteExigeAuthentificationEtEtablissement(): void
    {
        $anonymous = $this->call('POST', '/api/v1/sync/batches', ['Content-Type' => 'application/json'], '{}');
        $noEstablishment = $this->call('POST', '/api/v1/sync/batches', ['Authorization' => 'Bearer teacher:t1', 'Content-Type' => 'application/json'], '{}');
        $forbiddenEstablishment = $this->post([], $this->device['id'], $this->device['key'], ['establishment' => 'x1']);

        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame(400, $noEstablishment->getStatusCode());
        self::assertSame(403, $forbiddenEstablishment->getStatusCode());
    }

    public function testLesEnTetesObligatoiresSontControles(): void
    {
        $base = ['Authorization' => 'Bearer teacher:t1', 'X-Establishment-Id' => 'e1', 'Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'];
        $body = (string) gzencode('{"items":[]}');

        $noDevice = $this->call('POST', '/api/v1/sync/batches', $base + ['Idempotency-Key' => 'LOT-SYN-0010', 'X-Batch-Signature' => str_repeat('a', 64)], $body);
        $noBatch = $this->call('POST', '/api/v1/sync/batches', $base + ['X-Device-Id' => $this->device['id'], 'X-Batch-Signature' => str_repeat('a', 64)], $body);
        $badBatch = $this->call('POST', '/api/v1/sync/batches', $base + ['X-Device-Id' => $this->device['id'], 'Idempotency-Key' => 'court', 'X-Batch-Signature' => str_repeat('a', 64)], $body);

        self::assertSame(401, $noDevice->getStatusCode(), 'Appareil absent : identifiant d\'appareil et signature sont des identifiants.');
        self::assertSame(400, $noBatch->getStatusCode());
        self::assertSame(400, $badBatch->getStatusCode());
    }

    public function testLeResultatDUnLotSeRelitParSonIdentifiant(): void
    {
        $this->post([$this->item(['key' => 'seance-1', 'value' => 'present'])], $this->device['id'], $this->device['key'], ['batch_id' => 'LOT-SYN-0020']);

        [$response, $json] = $this->api('GET', '/sync/batches/LOT-SYN-0020', 'teacher');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('processed', $json['data']['status']);
        self::assertFalse($json['data']['replayed']);
        self::assertSame('applied', $json['data']['items'][0]['status']);
        self::assertSame(404, $this->api('GET', '/sync/batches/INCONNU-0001', 'teacher')[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/sync/batches/LOT-SYN-0020', 'nobody')[0]->getStatusCode());
    }

    public function testLActiviteDeLAppareilEstHorodatee(): void
    {
        $this->post([$this->item(['key' => 'a', 'value' => 1])], $this->device['id'], $this->device['key']);

        [, $list] = $this->api('GET', '/sync/devices', 'director');

        self::assertSame('2026-10-08T09:00:00+01:00', $list['data'][0]['last_seen_at']);
    }
}
