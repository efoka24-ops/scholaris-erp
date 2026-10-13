<?php

declare(strict_types=1);

namespace Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Application\PaymentService;
use Scholaris\Modules\Socle\Billing\Application\RecordPayment;
use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Tests\Support\Identity\IdentityTestCase;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Identity\RecordingSms;
use Tests\Support\Identity\TestClock;

/**
 * T146 : déroulé de specs/001-socle-plateforme/quickstart.md de bout en bout, sur SQLite, avec la PILE RÉELLE (authentification,
 * rôles et permissions d'Identity, Organisation, People, Billing, Documents, Audit : aucune doublure de module). Seuls l'horloge et
 * les passerelles SMS / e-mail sont remplacés. Les comptes sont créés avec leurs rôles réels ; la double authentification est
 * désactivée (AUTH_2FA_REQUIRED=false) pour garder le scénario lisible : elle est couverte par tests/Feature/Identity.
 */
final class QuickstartTest extends IdentityTestCase
{
    private string $e1;
    private string $e2;
    private string $x1;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function boot(): void
    {
        $this->clock = new TestClock();
        $this->sms = new RecordingSms();
        $this->mail = new RecordingMail();

        $config = Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log', 'url' => 'http://localhost', 'two_factor_required' => false],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => ['#^/api/v1/cash/receipts$#', '#^/api/v1/documents$#'], 'rate_limits' => []],
            'identity' => ['argon_memory' => 1024, 'argon_time' => 1, 'secret_key' => 'cle-de-test-identity'],
            'people' => ['secret_key' => 'cle-de-test-people'],
        ]);
        $this->kernel = Kernel::boot(dirname(__DIR__, 2), $config, [
            Clock::class => $this->clock,
            SmsGateway::class => $this->sms,
            MailGateway::class => $this->mail,
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();
    }

    protected function setUp(): void
    {
        $this->boot();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    private function api(string $method, string $path, string $token, ?array $body = null, ?string $establishment = null, array $headers = []): array
    {
        $headers += ['Authorization' => 'Bearer ' . $token];
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $request = new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        parse_str($request->getUri()->getQuery(), $query);
        $response = $this->kernel->handle($request->withQueryParams($query));
        $raw = (string) $response->getBody();
        $decoded = $raw !== '' && str_contains($response->getHeaderLine('Content-Type'), 'json') ? json_decode($raw, true) : [];

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /** Deux clients : t1 (deux établissements, un super administrateur et un caissier) et t2 (un établissement, un super administrateur). */
    private function twoTenants(): void
    {
        $this->createUser('t1', 'Awa', '+237691000101', [['super_admin', 'tenant', null]]);
        $this->createUser('t2', 'Boris', '+237691000201', [['super_admin', 'tenant', null]]);
        $this->tokens['awa'] = $this->token('+237691000101');
        $this->tokens['boris'] = $this->token('+237691000201');

        $this->e1 = (string) $this->api('POST', '/establishments', $this->tokens['awa'], ['name' => 'Collège Alpha', 'type' => 'secondary'])[1]['data']['id'];
        $this->e2 = (string) $this->api('POST', '/establishments', $this->tokens['awa'], ['name' => 'École Beta', 'type' => 'primary'])[1]['data']['id'];
        $this->x1 = (string) $this->api('POST', '/establishments', $this->tokens['boris'], ['name' => 'Autre client', 'type' => 'secondary'])[1]['data']['id'];
    }

    public function testUs1DeuxClientsIsolesEtVerrouillageApresCinqEchecs(): void
    {
        $this->twoTenants();

        [$response, $json] = $this->api('GET', '/me/contexts', $this->tokens['awa']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $ids = array_column(array_filter($json['data'], static fn (array $tile): bool => $tile['kind'] === 'establishment'), 'id');
        sort($ids);
        $expected = [$this->e1, $this->e2];
        sort($expected);
        self::assertSame($expected, $ids, 'Le client 1 ne voit que ses deux établissements.');

        [$other] = $this->api('GET', '/me/contexts', $this->tokens['boris']);
        self::assertSame([$this->x1], array_column($this->json($other)['data'], 'id'));

        // Appel croisé : établissement ou ressource d'un autre client.
        self::assertContains($this->api('GET', '/establishments/' . $this->x1, $this->tokens['awa'])[0]->getStatusCode(), [403, 404]);
        self::assertContains($this->api('GET', '/people', $this->tokens['awa'], null, $this->x1)[0]->getStatusCode(), [403, 404]);

        // 5 échecs : verrouillage.
        $this->createUser('t1', 'Cyril', '+237691000102');
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(401, $this->login('+237691000102', 'faux-' . $i)->getStatusCode());
        }
        self::assertSame(423, $this->login('+237691000102', 'faux-5')->getStatusCode());
        self::assertSame(423, $this->login('+237691000102')->getStatusCode(), 'Même avec le bon mot de passe.');
    }

    public function testUs2UnParametreSurchargeIndiqueSaSourceEtSesNiveauxMasques(): void
    {
        $this->twoTenants();

        self::assertSame(200, $this->api('PUT', '/settings', $this->tokens['awa'], ['scope' => 'tenant', 'key' => 'report.rounding', 'value' => 1], $this->e1)[0]->getStatusCode());
        self::assertSame(200, $this->api('PUT', '/settings', $this->tokens['awa'], ['scope' => 'establishment', 'key' => 'report.rounding', 'value' => 0], $this->e1)[0]->getStatusCode());

        [$response, $json] = $this->api('GET', '/settings/effective?filter[key]=report.rounding', $this->tokens['awa'], null, $this->e1);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $json['data']['value']);
        self::assertSame('establishment', $json['data']['source']);
        self::assertNotEmpty($json['data']['overridden'] ?? $json['data']['trace'] ?? [], 'La trace des niveaux masqués est restituée.');

        [, $second] = $this->api('GET', '/settings/effective?filter[key]=report.rounding', $this->tokens['awa'], null, $this->e2);
        self::assertSame(1, $second['data']['value'], 'Le second établissement hérite du client.');
        self::assertSame('tenant', $second['data']['source']);

        [, $foreign] = $this->api('GET', '/settings/effective?filter[key]=report.rounding', $this->tokens['boris'], null, $this->x1);
        self::assertNotSame(1, $foreign['data']['value'] ?? null, 'Un autre client ne voit pas les réglages du premier.');
    }

    public function testUs3UnDoublonDePersonneRenvoie409AvecLaFicheExistante(): void
    {
        $this->twoTenants();
        $nadia = ['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2010-05-14', 'phone' => '+237677123456'];

        [$created, $first] = $this->api('POST', '/people', $this->tokens['awa'], $nadia, $this->e1);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        [$parent] = $this->api('POST', '/people', $this->tokens['awa'], ['first_name' => 'Paul', 'last_name' => 'Foka', 'phone' => '+237677000111'], $this->e1);
        self::assertSame(201, $parent->getStatusCode());

        [$duplicate, $json] = $this->api('POST', '/people', $this->tokens['awa'], $nadia, $this->e1);
        self::assertSame(409, $duplicate->getStatusCode());
        self::assertSame($first['data']['id'], $json['duplicates'][0]['person']['id']);

        // Le même état civil chez un autre client n'est pas un doublon.
        self::assertSame(201, $this->api('POST', '/people', $this->tokens['boris'], $nadia, $this->x1)[0]->getStatusCode());
    }

    public function testUs7LeJournalDAuditDetecteLaModificationDUneLigne(): void
    {
        $this->twoTenants();
        $this->api('POST', '/people', $this->tokens['awa'], ['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2010-05-14'], $this->e1);
        $audit = $this->kernel->container()->get(AuditLog::class);

        $intact = $audit->verify('t1');
        self::assertTrue($intact->intact);
        self::assertGreaterThan(0, $intact->checked);

        $pdo = $this->pdo();
        $pdo->exec("UPDATE audit_log SET actor_id = 'intrus' WHERE tenant_id = 't1' AND seq = 1");
        $broken = $audit->verify('t1');
        self::assertFalse($broken->intact);
        self::assertSame(1, $broken->brokenAt);
        self::assertTrue($audit->verify('t2')->intact, 'La chaîne de l\'autre client n\'est pas touchée.');
    }

    public function testUs4PaiementIdempotentEtRecusSansTrou(): void
    {
        $this->twoTenants();
        $cashier = $this->createUser('t1', 'Cathy', '+237691000103', [['caissier', 'establishment', $this->e1]]);
        $admin = $this->tokens['awa'];
        $cashierToken = $this->token('+237691000103');
        $pdo = $this->pdo();
        $now = '2026-10-08T09:00:00+01:00';

        // Un apprenant inscrit, son garant, une grille de frais et une facture, par l'API réelle.
        $learner = (string) $this->api('POST', '/people', $admin, ['first_name' => 'Yann', 'last_name' => 'Foka', 'birth_date' => '2011-03-02'], $this->e1)[1]['data']['id'];
        $guarantor = (string) $this->api('POST', '/people', $admin, ['first_name' => 'Paul', 'last_name' => 'Foka', 'phone' => '+237670000000'], $this->e1)[1]['data']['id'];
        [$relation] = $this->api('POST', '/people/' . $learner . '/relations', $admin, ['related_person_id' => $guarantor, 'kind' => 'financial_guarantor'], $this->e1);
        self::assertContains($relation->getStatusCode(), [200, 201], (string) $relation->getBody());
        $pdo->prepare('INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, academic_year_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['EN1', 't1', $learner, $this->e1, 'ay1', 'active', $now, $now]);
        $item = (string) $this->api('POST', '/fee-items', $admin, ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 450000], $this->e1)[1]['data']['id'];
        $this->api('POST', '/fee-schedules', $admin, ['fee_item_id' => $item, 'academic_year_id' => 'ay1', 'amount_xaf' => 450000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 450000]]], $this->e1);
        [$invoice, $invoiceJson] = $this->api('POST', '/invoices', $admin, ['enrollment_id' => 'EN1'], $this->e1);
        self::assertSame(201, $invoice->getStatusCode(), (string) $invoice->getBody());

        [$session] = $this->api('POST', '/cash-sessions', $cashierToken, ['float_xaf' => 50000], $this->e1);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getBody());
        $reference = $this->api('POST', '/payment-references', $admin, ['guarantor_id' => $guarantor], $this->e1);
        self::assertSame(201, $reference[0]->getStatusCode(), (string) $reference[0]->getBody());

        $body = ['amount_xaf' => 1000, 'family_reference' => (string) $reference[1]['data']['reference']];
        [$first, $firstJson] = $this->api('POST', '/cash/receipts', $cashierToken, $body, $this->e1, ['Idempotency-Key' => 'cle-quickstart-0001']);
        [$replay, $replayJson] = $this->api('POST', '/cash/receipts', $cashierToken, $body, $this->e1, ['Idempotency-Key' => 'cle-quickstart-0001']);
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame($firstJson['data']['id'], $replayJson['data']['id'], 'Même clé : un seul paiement.');
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());

        // 99 encaissements de plus : reçus 1..100 sans trou.
        $service = $this->kernel->container()->get(PaymentService::class);
        for ($i = 2; $i <= 100; $i++) {
            $service->record(new RecordPayment('t1', $this->e1, $cashier['user_id'], PaymentChannel::Cash, 1000, 'lot-' . $i, (string) $reference[1]['data']['reference']));
        }
        $sequence = array_map('intval', $pdo->query('SELECT seq_no FROM receipts ORDER BY seq_no')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(range(1, 100), $sequence);
    }

    public function testUs6UnDocumentEmisSeVerifieEnPublicPuisEstRevoque(): void
    {
        $this->twoTenants();
        $person = (string) $this->api('POST', '/people', $this->tokens['awa'], ['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2010-05-14'], $this->e1)[1]['data']['id'];

        [$issued, $json] = $this->api('POST', '/documents', $this->tokens['awa'], ['type' => 'attestation_simple', 'subject_person_id' => $person, 'data' => ['academic_year' => '2026-2027', 'class_name' => '3eA']], $this->e1, ['Idempotency-Key' => 'cle-quickstart-doc-1']);
        self::assertSame(201, $issued->getStatusCode(), (string) $issued->getBody());
        $token = (string) $json['data']['verification_token'];

        $public = $this->kernel->handle(new ServerRequest('GET', '/api/v1/public/verify/' . $token));
        self::assertSame(200, $public->getStatusCode());
        self::assertSame('valid', $this->json($public)['data']['status']);

        [$revoked] = $this->api('POST', '/documents/' . $json['data']['id'] . '/revoke', $this->tokens['awa'], ['reason' => 'Erreur de classe'], $this->e1);
        self::assertSame(200, $revoked->getStatusCode(), (string) $revoked->getBody());
        $after = $this->kernel->handle(new ServerRequest('GET', '/api/v1/public/verify/' . $token));
        self::assertSame('revoked', $this->json($after)['data']['status']);
    }
}
