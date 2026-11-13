<?php

declare(strict_types=1);

namespace Tests\Support\Sync;

use DI\Container;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests du module Sync : Kernel de test (SQLite en mémoire migrée), horloge figée et doublures des droits et des
 * paramètres. Un gestionnaire de test est branché sous `sync.handlers.test.record`.
 *
 * Client t1, établissements e1 et e2 ; client t2, établissement x1. Utilisateurs : « director » (soumet et gère les appareils),
 * « teacher » (soumet), « nobody » (aucun droit), « other » (client t2, soumet et gère les appareils sur x1).
 */
abstract class SyncTestCase extends TestCase
{
    protected Kernel $kernel;
    protected StubAccess $access;
    protected Permissions $permissions;
    protected ArraySettings $settings;
    protected FixedClock $clock;
    protected RecordingHandler $handler;

    /** @param array<string, mixed> $settings */
    protected function bootSync(array $settings = [], string $secretKey = 'cle-de-test-sync'): void
    {
        $this->permissions = new Permissions();
        $this->settings = new ArraySettings($settings);
        $this->clock = new FixedClock();
        $this->handler = new RecordingHandler();
        $this->access = new StubAccess();

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
            'sync' => ['secret_key' => $secretKey],
        ]), [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            SettingsReader::class => $this->settings,
            Clock::class => $this->clock,
            'sync.handlers.test.record' => $this->handler,
        ]);
        $this->container()->get(Migrator::class)->migrate();

        foreach (['director', 'teacher', 'nobody'] as $user) {
            $this->access->grant($user, 'e1');
            $this->access->grant($user, 'e2');
        }
        $this->access->grant('other', 'x1');
        $this->permissions->grant('director', 'sync.batches.submit', 'sync.devices.manage');
        $this->permissions->grant('teacher', 'sync.batches.submit');
        $this->permissions->grant('other', 'sync.batches.submit', 'sync.devices.manage');
    }

    protected function container(): Container
    {
        return $this->kernel->container();
    }

    protected function connections(): ConnectionFactory
    {
        return $this->container()->get(ConnectionFactory::class);
    }

    protected static function ulid(): string
    {
        return (string) new Ulid();
    }

    /**
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $path, array $headers = [], string $body = ''): ResponseInterface
    {
        $request = new ServerRequest($method, $path, $headers, $body);
        parse_str($request->getUri()->getQuery(), $query);

        return $this->kernel->handle($request->withQueryParams($query));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user, ?array $body = null, string $tenant = 't1', string $establishment = 'e1'): array
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = (string) $response->getBody();
        $decoded = $raw !== '' ? json_decode($raw, true) : [];

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /**
     * Inscrit un appareil ; la clé n'est rendue qu'ici.
     *
     * @return array{id: string, key: string}
     */
    protected function registerDevice(string $user = 'director', string $tenant = 't1', string $establishment = 'e1', string $label = 'Téléphone SYN-ECOLE-01'): array
    {
        [$response, $json] = $this->api('POST', '/sync/devices', $user, ['label' => $label], $tenant, $establishment);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return ['id' => (string) $json['data']['id'], 'key' => (string) $json['data']['key']];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $options user, tenant, establishment, batch_id, key (clé de signature), signature, gzip, raw (corps brut déjà compressé)
     */
    protected function post(array $items, string $deviceId, string $key, array $options = []): ResponseInterface
    {
        $batchId = (string) ($options['batch_id'] ?? self::ulid());
        $raw = $options['raw'] ?? null;
        if ($raw === null) {
            $json = json_encode(['items' => $items], JSON_THROW_ON_ERROR);
            $raw = ($options['gzip'] ?? true) ? (string) gzencode($json) : $json;
        }
        $signature = array_key_exists('signature', $options)
            ? $options['signature']
            : hash_hmac('sha256', $deviceId . "\n" . $batchId . "\n" . $raw, (string) ($options['key'] ?? $key));

        $headers = [
            'Authorization' => 'Bearer ' . ($options['user'] ?? 'teacher') . ':' . ($options['tenant'] ?? 't1'),
            'X-Establishment-Id' => (string) ($options['establishment'] ?? 'e1'),
            'X-Device-Id' => $deviceId,
            'Idempotency-Key' => $batchId,
            'Content-Type' => 'application/json',
        ];
        if (($options['gzip'] ?? true) === true) {
            $headers['Content-Encoding'] = 'gzip';
        }
        if ($signature !== null) {
            $headers['X-Batch-Signature'] = (string) $signature;
        }

        return $this->call('POST', '/api/v1/sync/batches', $headers, (string) $raw);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{kind: string, client_uuid: string, payload: array<string, mixed>}
     */
    protected function item(array $payload, ?string $uuid = null, string $kind = 'test.record'): array
    {
        return ['kind' => $kind, 'client_uuid' => $uuid ?? self::ulid(), 'payload' => $payload];
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
