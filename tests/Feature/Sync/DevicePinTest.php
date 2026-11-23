<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use Tests\Support\Sync\SyncTestCase;

/** T608 (PP7) : code personnel sur appareil partagé ; 5 codes erronés verrouillent l'appareil et rendent l'ordre d'effacement. */
final class DevicePinTest extends SyncTestCase
{
    protected function setUp(): void
    {
        $this->bootSync();
    }

    public function testLeCodePersonnelDeverrouilleEtNEstJamaisStockeEnClair(): void
    {
        $device = $this->registerDevice();
        [$set] = $this->api('PUT', '/sync/devices/' . $device['id'] . '/pin', 'teacher', ['code' => '482913']);
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());

        [$ok, $json] = $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'teacher', ['code' => '482913']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertTrue($json['data']['unlocked']);
        self::assertFalse($json['data']['wipe']);

        $stored = json_encode($this->connections()->pdo()->query('SELECT * FROM sync_device_pins')->fetchAll(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('482913', $stored);
        self::assertStringNotContainsString('482913', json_encode($this->connections()->pdo()->query('SELECT * FROM audit_log')->fetchAll(), JSON_THROW_ON_ERROR));
        self::assertSame(422, $this->api('PUT', '/sync/devices/' . $device['id'] . '/pin', 'teacher', ['code' => '12ab'])[0]->getStatusCode());
        self::assertSame(403, $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'nobody', ['code' => '482913'])[0]->getStatusCode());
    }

    public function testCinqCodesErronesVerrouillentLAppareilEtRendentLOrdreDEffacement(): void
    {
        $device = $this->registerDevice();
        $this->api('PUT', '/sync/devices/' . $device['id'] . '/pin', 'teacher', ['code' => '482913']);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            [$response] = $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'teacher', ['code' => '000000']);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame((string) (5 - $attempt), $response->getHeaderLine('X-Unlock-Attempts-Remaining'));
        }
        [$fifth] = $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'teacher', ['code' => '000000']);
        self::assertSame(423, $fifth->getStatusCode(), (string) $fifth->getBody());
        self::assertSame('true', $fifth->getHeaderLine('X-Device-Wipe'));

        [$after] = $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'teacher', ['code' => '482913']);
        self::assertSame(423, $after->getStatusCode(), 'Le bon code ne déverrouille plus un appareil verrouillé.');
        self::assertSame('true', $after->getHeaderLine('X-Device-Wipe'));

        [, $list] = $this->api('GET', '/sync/devices', 'director');
        self::assertNotNull($list['data'][0]['locked_at']);
        self::assertSame(401, $this->post([$this->item(['n' => 1])], $device['id'], $device['key'])->getStatusCode(), 'Un appareil verrouillé ne soumet plus de lot.');
        self::assertStringContainsString('sync.device_locked', json_encode($this->connections()->pdo()->query('SELECT action FROM audit_log')->fetchAll(), JSON_THROW_ON_ERROR));
    }

    public function testUnSuccesRemetLeCompteurAZeroEtUnAppareilRevoqueRecoitLOrdreDEffacement(): void
    {
        $device = $this->registerDevice();
        $this->api('PUT', '/sync/devices/' . $device['id'] . '/pin', 'teacher', ['code' => '482913']);
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'teacher', ['code' => '111111']);
        }
        self::assertSame(200, $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'teacher', ['code' => '482913'])[0]->getStatusCode());
        [$again] = $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'teacher', ['code' => '111111']);
        self::assertSame('4', $again->getHeaderLine('X-Unlock-Attempts-Remaining'));

        $this->api('POST', '/sync/devices/' . $device['id'] . '/revoke', 'director');
        [$revoked] = $this->api('POST', '/sync/devices/' . $device['id'] . '/unlock', 'teacher', ['code' => '482913']);
        self::assertSame(423, $revoked->getStatusCode());
        self::assertSame('true', $revoked->getHeaderLine('X-Device-Wipe'));
    }
}
