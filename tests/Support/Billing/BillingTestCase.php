<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use DI\Container;

use function DI\factory;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Infrastructure\PdoAuditLog;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\SimulatedPaymentGateway;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests du module Billing : Kernel de test (SQLite en mémoire migrée), horloge figée et doublures des
 * contrats des autres modules (personnes, inscriptions, messagerie, paramètres, établissements, droits).
 */
abstract class BillingTestCase extends TestCase
{
    protected Kernel $kernel;
    protected StubAccess $access;

    public const SECRET = 'secret-de-test-webhook';

    protected Permissions $permissions;
    protected FixedClock $clock;
    protected InMemoryPeople $people;
    protected InMemoryEnrollments $enrollments;
    protected RecordingMessenger $messenger;
    protected ArraySettings $settings;
    protected InMemoryEstablishments $establishments;
    protected FlakyAudit $audit;
    private int $keys = 0;

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $definitions liaisons de conteneur supplémentaires (enregistrements d'application)
     */
    protected function bootBilling(array $settings = [], array $definitions = []): void
    {
        $this->permissions = new Permissions();
        $this->clock = new FixedClock();
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->messenger = new RecordingMessenger();
        $this->settings = new ArraySettings($settings + ['billing.receipt_code' => 'IPES']);
        $this->establishments = new InMemoryEstablishments();
        $this->establishments->add('t1', 'e1', 'IPES Douala');
        $this->establishments->add('t1', 'e2', 'Collège Le Pivot');
        $this->establishments->add('t2', 'x1', 'Autre École');

        $self = $this;
        $this->access = new StubAccess();
        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => ['#^/api/v1/payments$#', '#^/api/v1/cash/receipts$#'], 'rate_limits' => []],
        ]), [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            Clock::class => $this->clock,
            PersonDirectory::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments,
            Messenger::class => $this->messenger,
            SettingsReader::class => $this->settings,
            EstablishmentDirectory::class => $this->establishments,
            PaymentGateway::class => new SimulatedPaymentGateway(self::SECRET),
            AuditLog::class => factory(static function (ContainerInterface $c) use ($self): FlakyAudit {
                $self->audit = new FlakyAudit(new PdoAuditLog($c->get(ConnectionFactory::class), 'Africa/Douala'));

                return $self->audit;
            }),
        ] + $definitions);
        $this->container()->get(Migrator::class)->migrate();
        $this->kernel->container()->get(AuditLog::class);

        foreach (['manager', 'cashier', 'cashier2', 'supervisor', 'reader'] as $user) {
            $this->access->grant($user, 'e1');
            $this->access->grant($user, 'e2');
        }
        $this->permissions->grant('manager', 'billing.read', 'billing.manage');
        $this->permissions->grant('cashier', 'billing.read', 'billing.cash', 'billing.reversal.request');
        $this->permissions->grant('cashier2', 'billing.read', 'billing.cash', 'billing.reversal.request', 'billing.reversal.approve');
        $this->permissions->grant('supervisor', 'billing.read', 'billing.reversal.approve');
        $this->permissions->grant('reader', 'billing.read');
    }

    protected function container(): Container
    {
        return $this->kernel->container();
    }

    protected function connections(): ConnectionFactory
    {
        return $this->container()->get(ConnectionFactory::class);
    }

    /** @param array<string, string> $headers */
    protected function call(string $method, string $path, array $headers = [], string $body = ''): ResponseInterface
    {
        $request = new ServerRequest($method, $path, $headers, $body);
        parse_str($request->getUri()->getQuery(), $query);

        return $this->kernel->handle($request->withQueryParams($query));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user, ?array $body = null, string $tenant = 't1', string $establishment = 'e1', array $headers = []): array
    {
        $headers += ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = (string) $response->getBody();
        $decoded = $raw !== '' && str_starts_with($response->getHeaderLine('Content-Type'), 'application/') && !str_contains($response->getHeaderLine('Content-Type'), 'pdf')
            ? json_decode($raw, true) : [];

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /**
     * Paiement à travers l'API (clé d'idempotence générée).
     *
     * @param array<string, mixed> $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function pay(string $user, array $body, string $path = '/payments', ?string $key = null, string $establishment = 'e1'): array
    {
        return $this->api('POST', $path, $user, $body, 't1', $establishment, ['Idempotency-Key' => $key ?? 'cle-test-' . ++$this->keys . '-' . bin2hex(random_bytes(4))]);
    }

    /** Jeu de l'exemple IPES : frais d'inscription 50 000 + pension 450 000 en 3 tranches de 150 000 (avant bourse). */
    protected function seedIpesGrid(string $establishment = 'e1'): void
    {
        $registration = $this->api('POST', '/fee-items', 'manager', ['kind' => 'registration', 'name' => 'Frais d\'inscription', 'amount_xaf' => 50000], 't1', $establishment)[1]['data']['id'];
        $tuition = $this->api('POST', '/fee-items', 'manager', ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 450000], 't1', $establishment)[1]['data']['id'];

        $this->api('POST', '/fee-schedules', 'manager', [
            'fee_item_id' => $registration, 'academic_year_id' => 'ay1', 'amount_xaf' => 50000, 'terms' => [['due_on' => '2026-10-08', 'amount' => 50000]],
        ], 't1', $establishment);
        $this->api('POST', '/fee-schedules', 'manager', [
            'fee_item_id' => $tuition, 'academic_year_id' => 'ay1', 'amount_xaf' => 450000,
            'terms' => [['due_on' => '2026-10-15', 'amount' => 150000], ['due_on' => '2027-01-15', 'amount' => 150000], ['due_on' => '2027-04-15', 'amount' => 150000]],
        ], 't1', $establishment);
    }

    /**
     * Inscrit un apprenant (avec son garant) et génère sa facture ; renvoie la facture.
     *
     * @param list<array<string, mixed>> $discounts
     * @return array<string, mixed>
     */
    protected function invoiceFor(string $learner, string $guarantor, string $enrollment, string $establishment = 'e1', array $discounts = []): array
    {
        $this->people->add('t1', $learner, ucfirst($learner), 'Foka');
        if ($this->people->find('t1', $guarantor) === null) {
            $this->people->add('t1', $guarantor, 'Parent', 'Foka', '+237670000000');
        }
        $this->people->link('t1', $guarantor, $learner);
        $this->enrollments->add('t1', $enrollment, $learner, $establishment);

        $body = ['enrollment_id' => $enrollment] + ($discounts === [] ? [] : ['discounts' => $discounts]);
        [$response, $json] = $this->api('POST', '/invoices', 'manager', $body, 't1', $establishment);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** @return array<string, mixed> */
    protected function reference(string $guarantor): array
    {
        [$response, $json] = $this->api('POST', '/payment-references', 'manager', ['guarantor_id' => $guarantor]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    protected function openCash(string $user = 'cashier', int $float = 50000): string
    {
        [$response, $json] = $this->api('POST', '/cash-sessions', $user, ['float_xaf' => $float]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $json['data']['id'];
    }

    /** @return list<array<string, mixed>> */
    protected function rows(string $sql): array
    {
        return $this->connections()->pdo()->query($sql)->fetchAll();
    }
}
