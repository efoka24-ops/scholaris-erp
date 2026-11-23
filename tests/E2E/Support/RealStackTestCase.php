<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Nyholm\Psr7\ServerRequest;
use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Console\CreateAdministrator;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Identity\RecordingSms;
use Tests\Support\Identity\TestClock;

/**
 * Recette de bout en bout : PILE RÉELLE (Kernel, middlewares, authentification Argon2id, 2FA TOTP, rôles et permissions
 * d'Identity, tous les modules, Dompdf et QR réels, simulateur de paiement), base SQLite en mémoire migrée.
 * Seules les passerelles externes (SMS, e-mail) et l'horloge de limitation de débit sont remplacées.
 *
 * La double authentification reste ACTIVE : chaque rôle sensible est enrôlé en TOTP par l'API (setup puis confirm).
 */
abstract class RealStackTestCase extends TestCase
{
    public const WEBHOOK_SECRET = 'secret-webhook-de-recette';

    protected Kernel $kernel;
    protected TestClock $clock;
    protected RecordingSms $sms;
    protected RecordingMail $mail;
    /** @var array<string, string> secret TOTP par identifiant de connexion */
    protected array $totpSecrets = [];
    protected string $storage;
    private int $phoneSeq = 0;

    /** @param array<string, mixed> $configOverrides */
    protected function bootRealStack(array $configOverrides = []): void
    {
        // Horloge : prochain jour ouvré à 10 h (Douala), toujours postérieure à l'heure système (admin:create date les
        // affectations avec l'heure système) et dans la plage d'envoi 7 h - 21 h des notifications.
        $this->clock = new TestClock(self::nextSchoolMorning());
        $this->sms = new RecordingSms();
        $this->mail = new RecordingMail();
        $this->storage = sys_get_temp_dir() . '/scholaris-e2e-' . bin2hex(random_bytes(4));
        @mkdir($this->storage . '/uploads', 0o777, true);

        $config = Config::fromArray(array_replace_recursive([
            'app' => [
                'name' => 'SCHOLARIS', 'env' => 'testing', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1',
                'log_path' => 'storage/logs/test.log', 'url' => 'http://localhost', 'two_factor_required' => true,
            ],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => [
                'cors_origins' => ['https://app.scholaris.test'],
                'idempotent_paths' => ['#^/api/v1/payments$#', '#^/api/v1/cash/receipts$#', '#^/api/v1/documents$#'],
                'rate_limits' => [],
                'payment_webhook_secret' => self::WEBHOOK_SECRET,
            ],
            'identity' => ['argon_memory' => 1024, 'argon_time' => 1, 'secret_key' => 'cle-de-recette-identity'],
            'people' => ['secret_key' => 'cle-de-recette-people', 'storage_path' => $this->storage . '/uploads'],
            'documents' => ['pdf_renderer' => 'dompdf', 'qr_generator' => 'library', 'pdf_paper' => 'A4', 'queue' => ['chunk' => 50, 'max_attempts' => 3, 'stale_after' => 600]],
            'backup' => ['path' => $this->storage . '/backups', 'key' => '', 'keep' => 3],
        ], $configOverrides));

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), $config, [
            Clock::class => $this->clock,
            SmsGateway::class => $this->sms,
            MailGateway::class => $this->mail,
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();
    }

    public static function nextSchoolMorning(): int
    {
        $day = new \DateTimeImmutable('tomorrow 10:00', new \DateTimeZone('Africa/Douala'));
        while ((int) $day->format('N') > 5) {
            $day = $day->modify('+1 day');
        }

        return $day->getTimestamp();
    }

    protected function pdo(): PDO
    {
        return $this->kernel->container()->get(ConnectionFactory::class)->pdo();
    }

    /**
     * Création d'un client et de son premier super administrateur par l'outil d'installation réel (admin:create).
     *
     * @return array{tenant_id: string, login: string, password: string, user_id: string}
     */
    protected function createClient(string $name, string $firstName, string $lastName): array
    {
        $creator = new CreateAdministrator($this->kernel->container()->get(ConnectionFactory::class));

        $created = $creator->create($name, $firstName, $lastName, $this->nextPhone());
        // L'outil date l'affectation à l'heure système : l'horloge de test (figée) doit la dépasser.
        $this->clock->advance(max(0, time() - $this->clock->now()) + 1);

        return $created;
    }

    protected function nextPhone(): string
    {
        return sprintf('+2376990%05d', ++$this->phoneSeq);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, ?string $token, ?array $body = null, ?string $establishment = null, array $headers = []): array
    {
        if ($token !== null) {
            $headers += ['Authorization' => 'Bearer ' . $token];
        }
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $headers += ['User-Agent' => 'Mozilla/5.0 (Recette E2E) Chrome/120.0', 'Accept-Language' => 'fr'];
        $raw = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);

        return $this->send(new ServerRequest($method, '/api/v1' . $path, $headers, $raw));
    }

    /** @return array{0: ResponseInterface, 1: array<string, mixed>} */
    protected function send(ServerRequest $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);
        $response = $this->kernel->handle($request->withQueryParams($query));
        $raw = (string) $response->getBody();
        $response->getBody()->rewind();
        $decoded = $raw !== '' && str_contains($response->getHeaderLine('Content-Type'), 'json') ? json_decode($raw, true) : [];

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /** Appel attendu en succès : échoue avec le corps de la réponse sinon. @return array<string, mixed> */
    protected function ok(string $method, string $path, ?string $token, ?array $body = null, ?string $establishment = null, array $headers = [], ?int $expected = null): array
    {
        [$response, $json] = $this->api($method, $path, $token, $body, $establishment, $headers);
        $status = $response->getStatusCode();
        $context = $method . ' ' . $path . ' -> ' . $status . ' ' . substr((string) $response->getBody(), 0, 1500);
        if ($expected !== null) {
            self::assertSame($expected, $status, $context);
        } else {
            self::assertTrue($status >= 200 && $status < 300, $context);
        }

        return $json;
    }

    /**
     * Connexion réelle ; si le rôle est sensible, enrôlement TOTP (première fois) ou vérification du code.
     */
    protected function signIn(string $login, string $password): string
    {
        [$response, $json] = $this->api('POST', '/auth/login', null, ['identifier' => $login, 'password' => $password]);
        self::assertSame(200, $response->getStatusCode(), 'Connexion ' . $login . ' : ' . $response->getBody());
        $token = (string) $json['token'];
        if (($json['two_factor_required'] ?? false) !== true) {
            return $token;
        }
        if (($json['two_factor_setup_required'] ?? false) === true) {
            $setup = $this->ok('POST', '/auth/2fa/setup', $token);
            $this->totpSecrets[$login] = (string) $setup['secret'];
            $this->ok('POST', '/auth/2fa/confirm', $token, ['code' => $this->totp($login)]);
        } else {
            $this->clock->advance(31);
            $this->ok('POST', '/auth/2fa/verify', $token, ['code' => $this->totp($login)]);
        }

        return $token;
    }

    protected function totp(string $login): string
    {
        return TOTP::createFromSecret($this->totpSecrets[$login])->at($this->clock->now());
    }

    /**
     * Crée un compte (personne + utilisateur) par l'API d'administration, puis lui donne un mot de passe par le parcours
     * d'activation réel (code reçu par SMS simulé). Renvoie [person_id, login, password].
     *
     * @return array{person_id: string, login: string, password: string}
     */
    protected function provisionUser(string $adminToken, string $establishment, string $firstName, string $lastName, string $role, ?string $personId = null): array
    {
        $phone = $this->nextPhone();
        $created = $this->ok('POST', '/users', $adminToken, ['first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone], $establishment, [], 201);
        $person = (string) $created['data']['person_id'];
        $this->ok('POST', '/role-assignments', $adminToken, ['person_id' => $person, 'role' => $role, 'scope_type' => 'establishment', 'scope_id' => $establishment], $establishment, [], 201);
        $password = 'Recette-Solide-' . substr(md5($phone), 0, 8) . '!';
        $this->activate($phone, $password);

        return ['person_id' => $person, 'login' => $phone, 'password' => $password];
    }

    /** Parcours d'activation réel : code à 6 chiffres reçu par SMS (simulé), ticket, mot de passe. */
    protected function activate(string $phone, string $password): void
    {
        $this->clock->advance(61);
        $this->ok('POST', '/auth/activation/request', null, ['identifier' => $phone]);
        $code = $this->sms->lastCode($phone);
        self::assertNotNull($code, 'Aucun code d\'activation reçu par ' . $phone);
        $verified = $this->ok('POST', '/auth/activation/verify', null, ['identifier' => $phone, 'code' => $code]);
        $this->ok('POST', '/auth/activation/password', null, ['ticket' => (string) $verified['ticket'], 'password' => $password]);
    }

    /** Attribution directe d'un rôle (outil d'installation ; utilisé quand l'API n'expose pas le périmètre voulu). */
    protected function grantRole(string $tenant, string $personId, string $role, string $scope, ?string $scopeId): void
    {
        $moment = $this->kernel->container()->get(Moment::class);
        $this->kernel->container()->get(PdoRoleAssignmentRepository::class)->insert(new RoleAssignment(
            (string) new Ulid(),
            $tenant,
            $personId,
            $role,
            ScopeType::from($scope),
            $scopeId,
            $scope === 'tenant' ? null : $scopeId,
            $moment->in(-60),
            null,
            null,
            null,
            $moment->iso(),
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->storage) && is_dir($this->storage)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($this->storage);
        }
    }
}
