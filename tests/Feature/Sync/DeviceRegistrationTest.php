<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use Tests\Support\Sync\SyncTestCase;

/**
 * Appareils : la clé est générée aléatoirement à l'inscription, rendue UNE fois, conservée chiffrée, jamais journalisée ;
 * un appareil se révoque ; tout est refusé sans le droit `sync.devices.manage`.
 */
final class DeviceRegistrationTest extends SyncTestCase
{
    protected function setUp(): void
    {
        $this->bootSync();
    }

    public function testLInscriptionRendUneCleAleatoireUneSeuleFois(): void
    {
        [$response, $json] = $this->api('POST', '/sync/devices', 'director', ['label' => 'Téléphone SYN-ECOLE-01']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $json['data']['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $json['data']['key'], 'Clé de 256 bits en hexadécimal.');
        self::assertSame('e1', $json['data']['establishment_id']);
        self::assertSame('Téléphone SYN-ECOLE-01', $json['data']['label']);
        self::assertNull($json['data']['revoked_at']);

        $second = $this->registerDevice();
        self::assertNotSame($json['data']['key'], $second['key'], 'Deux appareils n\'ont jamais la même clé.');
    }

    public function testLaCleNEstJamaisRelue(): void
    {
        $device = $this->registerDevice();

        [, $list] = $this->api('GET', '/sync/devices', 'director');
        self::assertCount(1, $list['data']);
        self::assertSame($device['id'], $list['data'][0]['id']);
        self::assertArrayNotHasKey('key', $list['data'][0]);
        self::assertStringNotContainsString($device['key'], json_encode($list, JSON_THROW_ON_ERROR));
        self::assertArrayHasKey('next_cursor', $list['meta']);
    }

    public function testLaCleEstChiffreeAuRepos(): void
    {
        $device = $this->registerDevice();

        $stored = (string) $this->connections()->pdo()->query('SELECT key_cipher FROM sync_devices')->fetchColumn();
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString($device['key'], $stored, 'La clé n\'est pas stockée en clair.');
        self::assertStringNotContainsString($device['key'], (string) json_encode($this->connections()->pdo()->query('SELECT * FROM sync_devices')->fetchAll(), JSON_THROW_ON_ERROR));
    }

    public function testLaCleNApparaitNiDansLeJournalNiDansLesLogs(): void
    {
        $device = $this->registerDevice();
        $this->api('POST', '/sync/devices/' . $device['id'] . '/revoke', 'director');

        $audit = json_encode($this->connections()->pdo()->query('SELECT * FROM audit_log')->fetchAll(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('sync.device_registered', $audit);
        self::assertStringContainsString('sync.device_revoked', $audit);
        self::assertStringNotContainsString($device['key'], $audit);
        $log = (string) @file_get_contents(dirname(__DIR__, 3) . '/storage/logs/test.log');
        self::assertStringNotContainsString($device['key'], $log);
    }

    public function testUnAppareilSeRevoque(): void
    {
        $device = $this->registerDevice();

        [$response, $json] = $this->api('POST', '/sync/devices/' . $device['id'] . '/revoke', 'director');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotNull($json['data']['revoked_at']);
        [$again, $second] = $this->api('POST', '/sync/devices/' . $device['id'] . '/revoke', 'director');
        self::assertSame(200, $again->getStatusCode(), 'Révoquer deux fois est sans effet.');
        self::assertSame($json['data']['revoked_at'], $second['data']['revoked_at'], 'La date de première révocation est conservée.');
    }

    public function testSansLeDroitDeGestionTouteOperationEstRefusee(): void
    {
        $device = $this->registerDevice();

        foreach (['teacher', 'nobody'] as $user) {
            self::assertSame(403, $this->api('POST', '/sync/devices', $user, ['label' => 'x'])[0]->getStatusCode(), $user);
            self::assertSame(403, $this->api('GET', '/sync/devices', $user)[0]->getStatusCode(), $user);
            self::assertSame(403, $this->api('POST', '/sync/devices/' . $device['id'] . '/revoke', $user)[0]->getStatusCode(), $user);
        }
        [, $list] = $this->api('GET', '/sync/devices', 'director');
        self::assertNull($list['data'][0]['revoked_at'], 'L\'appareil n\'a pas été révoqué par un utilisateur sans droit.');
    }

    public function testLeLibelleEstValideEtLesChampsInconnusRefuses(): void
    {
        self::assertSame(422, $this->api('POST', '/sync/devices', 'director', ['autre' => 1])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/sync/devices', 'director', ['label' => ''])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/sync/devices', 'director', ['label' => str_repeat('a', 121)])[0]->getStatusCode());
        [$response, $json] = $this->api('POST', '/sync/devices', 'director', ['label' => 'Ok', 'tenant_id' => 't2', 'key' => 'imposee']);
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('tenant_id', $json['errors']);
        self::assertArrayHasKey('key', $json['errors'], 'La clé n\'est jamais choisie par le client.');
    }

    public function testSansCleDeChiffrementLInscriptionEchoueSansRienStocker(): void
    {
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        $this->bootSync([], '');

        [$response] = $this->api('POST', '/sync/devices', 'director', ['label' => 'Téléphone SYN-ECOLE-01']);

        self::assertSame(500, $response->getStatusCode(), 'Fail-closed : jamais de clé en clair.');
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM sync_devices')->fetchColumn());
    }
}
