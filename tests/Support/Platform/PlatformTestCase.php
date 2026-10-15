<?php

declare(strict_types=1);

namespace Tests\Support\Platform;

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
use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\SimulatedPaymentGateway;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests du module Platform : Kernel de test (SQLite en mémoire migrée) avec les vrais Organisation, Identity
 * (création du premier administrateur), Audit ; doublures pour l'authentification, les permissions, la 2FA récente,
 * les notifications, l'horloge et la passerelle de paiement (simulateur signé).
 *
 * Acteurs : `root@platform` (administrateur de plateforme), `boss@<client>` (super administrateur d'un client),
 * `teacher@<client>` (utilisateur sans droit d'ouverture).
 */
abstract class PlatformTestCase extends TestCase
{
    public const SECRET = 'secret-webhook-plateforme';
    public const ROOT = 'root';

    protected Kernel $kernel;
    protected StubAccess $access;
    protected PlatformPermissions $permissions;
    protected PlatformRecentSecondFactor $secondFactor;
    protected RecordingDirectNotifier $notifier;
    protected FixedAccessibleEstablishments $accessible;
    protected PlatformTestClock $clock;

    /** @param array<string, mixed> $config */
    protected function bootPlatform(array $config = []): void
    {
        $this->access = new StubAccess();
        $this->permissions = new PlatformPermissions();
        $this->secondFactor = new PlatformRecentSecondFactor();
        $this->notifier = new RecordingDirectNotifier();
        $this->accessible = new FixedAccessibleEstablishments();
        $this->clock = new PlatformTestClock();

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray(array_replace_recursive([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log', 'url' => 'https://app.scholaris.test'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
            'identity' => ['argon_memory' => 1024, 'argon_time' => 1, 'secret_key' => 'cle-de-test-identity'],
        ], $config)), [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            RecentSecondFactor::class => $this->secondFactor,
            DirectNotifier::class => $this->notifier,
            AccessibleEstablishments::class => $this->accessible,
            Clock::class => $this->clock,
            PaymentGateway::class => new SimulatedPaymentGateway(self::SECRET),
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();

        $this->permissions->grant(
            self::ROOT,
            'platform',
            'platform.access',
            'platform.dashboard.read',
            'platform.clients.read',
            'platform.catalog.manage',
            'platform.requests.read',
            'platform.requests.decide',
            'platform.applications.manage',
            'platform.pricing.manage',
            'platform.payments.read',
        );
        $this->secondFactor->allow(self::ROOT);
    }

    /** Client avec un établissement du type donné ; `boss` y détient applications.manage. @return array{tenant: string, establishment: string} */
    protected function client(string $name, string $type = 'higher'): array
    {
        $provisioning = $this->kernel->container()->get(ClientProvisioning::class);
        $tenant = $provisioning->createTenant($name);
        $establishment = $provisioning->createEstablishment($tenant, $name, $type);
        $this->access->grant('boss', $establishment);
        $this->access->grant('teacher', $establishment);
        $this->permissions->grant('boss', $tenant, 'applications.manage');
        $this->accessible->set($tenant, 'boss', $establishment);
        $this->accessible->set($tenant, 'teacher', $establishment);

        return ['tenant' => $tenant, 'establishment' => $establishment];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, ?string $user = null, ?string $tenant = null, ?array $body = null, ?string $establishment = null, array $headers = []): array
    {
        if ($user !== null) {
            $headers['Authorization'] = 'Bearer ' . $user . ':' . ($tenant ?? 'platform');
        }
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->raw($method, $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function raw(string $method, string $path, array $headers = [], string $body = '', string $ip = '198.51.100.7'): array
    {
        $request = new ServerRequest($method, '/api/v1' . $path, $headers, $body, '1.1', ['REMOTE_ADDR' => $ip]);
        parse_str($request->getUri()->getQuery(), $query);
        $response = $this->kernel->handle($request->withQueryParams($query));
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /** @return array<string, mixed> */
    protected function requestBody(array $overrides = []): array
    {
        return array_replace([
            'institution_name' => 'Institut Supérieur du Littoral',
            'target_type' => 'higher',
            'city' => 'Douala',
            'region' => 'Littoral',
            'contact_first_name' => 'Awa',
            'contact_last_name' => 'Ngo Bassa',
            'contact_email' => 'direction@isl.test',
            'contact_phone' => '+237 690 00 00 01',
            'ministerial_authorization' => 'MINESUP/2024/0123',
            'app_key' => 'higher',
            'message' => 'Nous souhaitons utiliser IPES dès la rentrée.',
        ], $overrides);
    }

    /** Dépose une demande publique ; renvoie le jeton de suivi et l'identifiant. @return array{token: string, id: string} */
    protected function submitRequest(array $overrides = [], string $ip = '198.51.100.7'): array
    {
        [$response, $json] = $this->raw('POST', '/public/access-requests', ['Content-Type' => 'application/json'], json_encode($this->requestBody($overrides), JSON_THROW_ON_ERROR), $ip);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $id = (string) $this->connections()->pdo()->query('SELECT id FROM platform_access_requests ORDER BY id DESC LIMIT 1')->fetchColumn();

        return ['token' => (string) $json['data']['tracking_token'], 'id' => $id];
    }

    /** Signe et livre un webhook de paiement. @return array{0: ResponseInterface, 1: array<string, mixed>} */
    protected function webhook(string $eventId, string $type, int $amount, string $providerReference, ?string $secret = null): array
    {
        $body = json_encode(['event_id' => $eventId, 'type' => $type, 'amount' => $amount, 'provider_reference' => $providerReference], JSON_THROW_ON_ERROR);

        return $this->raw('POST', '/platform/payments/webhook', [
            'Content-Type' => 'application/json',
            'X-Signature' => hash_hmac('sha256', $body, $secret ?? self::SECRET),
        ], $body);
    }

    protected function connections(): ConnectionFactory
    {
        return $this->kernel->container()->get(ConnectionFactory::class);
    }

    /** @return array<string, mixed> */
    protected function entitlement(string $tenant, string $app = 'higher'): array
    {
        $statement = $this->connections()->pdo()->prepare('SELECT * FROM tenant_applications WHERE tenant_id = ? AND app_key = ?');
        $statement->execute([$tenant, $app]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return $row;
    }
}
