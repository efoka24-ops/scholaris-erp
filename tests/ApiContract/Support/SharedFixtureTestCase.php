<?php

declare(strict_types=1);

namespace Tests\ApiContract\Support;

use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;
use Scholaris\Core\Support\Ulid;
use Symfony\Component\Yaml\Yaml;
use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Jeu de données partagé par tous les cas d'un même test paramétré (construit une fois, par l'API réelle) :
 *  - client A : établissement, année, classe, élève inscrit et son parent, enseignant, caissier, comptes « apprenant »
 *    (rôle sans permission métier), facture, paiement, reçu, document, appel, épreuve et notes, conversation, etc. ;
 *  - client B : son super administrateur et son établissement.
 * Les identifiants réels de A servent à remplir les paramètres de chemin de chaque route.
 */
abstract class SharedFixtureTestCase extends RealStackTestCase
{
    use BuildsSchool;

    /** @var array<string, mixed>|null */
    private static ?array $shared = null;

    /** @var array<string, string> */
    protected array $tokens = [];
    protected string $schoolB = '';
    protected string $tenantB = '';
    /** @var array<string, true> identifiants (ULID) de toutes les lignes du client A */
    protected array $tenantAIds = [];
    /** @var array<string, string> */
    protected array $known = [];
    /** @var array<string, true> identifiants des données personnelles et financières du client A */
    protected array $sensitiveIds = [];

    protected function setUp(): void
    {
        if (self::$shared === null || self::$shared['class'] !== static::class) {
            $this->bootRealStack();
            $this->buildFixture();
            self::$shared = [
                'class' => static::class, 'kernel' => $this->kernel, 'clock' => $this->clock, 'sms' => $this->sms, 'mail' => $this->mail,
                'storage' => $this->storage, 'tenant' => $this->tenant, 'admin' => $this->admin, 'school' => $this->school, 'year' => $this->year,
                'periods' => $this->periods, 'class_id' => $this->class, 'tokens' => $this->tokens, 'schoolB' => $this->schoolB, 'tenantB' => $this->tenantB,
                'ids' => $this->tenantAIds, 'sensitive' => $this->sensitiveIds, 'known' => $this->known, 'totp' => $this->totpSecrets,
            ];

            return;
        }
        $s = self::$shared;
        [$this->kernel, $this->clock, $this->sms, $this->mail, $this->storage] = [$s['kernel'], $s['clock'], $s['sms'], $s['mail'], $s['storage']];
        [$this->tenant, $this->admin, $this->school, $this->year, $this->periods, $this->class] = [$s['tenant'], $s['admin'], $s['school'], $s['year'], $s['periods'], $s['class_id']];
        [$this->tokens, $this->schoolB, $this->tenantB, $this->tenantAIds, $this->known, $this->totpSecrets, $this->sensitiveIds] = [$s['tokens'], $s['schoolB'], $s['tenantB'], $s['ids'], $s['known'], $s['totp'], $s['sensitive']];
    }

    protected function tearDown(): void
    {
        // Le jeu partagé est conservé entre les cas ; nettoyé en fin de classe.
    }

    public static function tearDownAfterClass(): void
    {
        $storage = self::$shared['storage'] ?? null;
        self::$shared = null;
        if (is_string($storage) && is_dir($storage)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($storage);
        }
    }

    private function buildFixture(): void
    {
        $this->buildSchool('Client A', 'Collège A');
        $this->tokens['admin'] = $this->admin;

        $parent = $this->provisionUser($this->admin, $this->school, 'Paul', 'Foka', 'parent');
        $family = $this->enrollLearner('Nadia', 'Foka', '2011-05-14', $parent['person_id']);
        $this->enrollLearner('Yann', 'Foka', '2012-03-02', $parent['person_id'], true, 'male');
        $this->ok('POST', '/people/' . $family['learner'] . '/relations', $this->admin, ['related_person_id' => $parent['person_id'], 'kind' => 'parent'], $this->school);
        $this->tokens['parent'] = $this->signIn($parent['login'], $parent['password']);
        $this->known['learner'] = $family['learner'];

        $learnerAccount = $this->provisionUser($this->admin, $this->school, 'Lea', 'Apprenante', 'apprenant');
        $this->tokens['apprenant'] = $this->signIn($learnerAccount['login'], $learnerAccount['password']);
        $this->known['apprenant_person'] = $learnerAccount['person_id'];
        $this->known['apprenant_user'] = (string) $this->ok('GET', '/me', $this->tokens['apprenant'])['data']['id'];

        $teacher = $this->provisionUser($this->admin, $this->school, 'Tano', 'Prof', 'enseignant');
        $this->tokens['teacher'] = $this->signIn($teacher['login'], $teacher['password']);
        $this->ok('POST', '/staff', $this->admin, ['person_id' => $teacher['person_id'], 'kind' => 'permanent'], $this->school, [], 201);
        $weekday = (int) date('N', $this->clock->now());
        $slot = (string) $this->ok('POST', '/timetable-slots', $this->admin, ['subject_label' => 'Mathématiques', 'teacher_person_id' => $teacher['person_id'], 'structure_ids' => [$this->class], 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '10:00', 'valid_from' => '2026-09-01'], $this->school, [], 201)['data']['id'];

        // Organisation et calendrier.
        $this->ok('POST', '/org-units', $this->admin, ['type' => 'department', 'name' => 'Sciences'], $this->school, [], 201);
        $this->ok('POST', '/rooms', $this->admin, ['name' => 'Salle A', 'capacity' => 30], $this->school, [], 201);
        $this->ok('POST', '/calendar-events', $this->admin, ['kind' => 'event', 'title' => 'Journée portes ouvertes', 'starts_on' => '2026-11-14'], $this->school, [], 201);

        // Facturation.
        $cashier = $this->provisionUser($this->admin, $this->school, 'Cathy', 'Caisse', 'caissier');
        $this->tokens['cashier'] = $this->signIn($cashier['login'], $cashier['password']);
        $item = (string) $this->ok('POST', '/fee-items', $this->admin, ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 300000], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/fee-schedules', $this->admin, ['fee_item_id' => $item, 'academic_year_id' => $this->year, 'amount_xaf' => 300000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 150000], ['due_on' => '2027-01-15', 'amount' => 150000]]], $this->school, [], 201);
        $this->ok('POST', '/invoices', $this->admin, ['enrollment_id' => $family['enrollment']], $this->school, [], 201);
        $reference = (string) $this->ok('POST', '/payment-references', $this->admin, ['guarantor_id' => $parent['person_id']], $this->school, [], 201)['data']['reference'];
        $this->known['reference'] = $reference;
        $this->ok('POST', '/cash-sessions', $this->tokens['cashier'], ['float_xaf' => 10000], $this->school, [], 201);
        $this->ok('POST', '/cash/receipts', $this->tokens['cashier'], ['amount_xaf' => 50000, 'family_reference' => $reference], $this->school, ['Idempotency-Key' => 'contrat-cash-0001'], 201);
        $this->ok('POST', '/cash/receipts', $this->tokens['cashier'], ['amount_xaf' => 20000, 'family_reference' => $reference], $this->school, ['Idempotency-Key' => 'contrat-cash-0002'], 201);

        // Documents.
        $doc = $this->ok('POST', '/documents', $this->admin, ['type' => 'attestation_simple', 'subject_person_id' => $family['learner'], 'data' => ['academic_year' => '2026-2027', 'class_name' => '3e A']], $this->school, ['Idempotency-Key' => 'contrat-doc-0001'], 201)['data'];
        $this->known['token'] = (string) $doc['verification_token'];
        $this->ok('POST', '/documents', $this->admin, ['type' => 'attestation_simple', 'subject_person_id' => $family['learner'], 'data' => ['academic_year' => '2026-2027', 'class_name' => '3e A']], $this->school, ['Idempotency-Key' => 'contrat-doc-0002'], 201);

        // Appel et notes.
        $this->ok('POST', '/attendance-sessions/sync', $this->tokens['teacher'], ['sessions' => [[
            'client_uuid' => (string) new Ulid(), 'slot_id' => $slot, 'date' => date('Y-m-d', $this->clock->now()), 'validate' => true,
            'records' => [['person_id' => $family['learner'], 'status' => 'A', 'client_uuid' => (string) new Ulid()]],
        ]]], $this->school);
        $subject = (string) $this->ok('POST', '/subjects', $this->admin, ['code' => 'MATH', 'name' => 'Mathématiques', 'kind' => 'subject', 'coefficient' => 2, 'credits' => 0], $this->school, [], 201)['data']['id'];
        $assessment = (string) $this->ok('POST', '/assessments', $this->admin, ['subject_id' => $subject, 'structure_id' => $this->class, 'period_id' => $this->periods[0], 'academic_year_id' => $this->year, 'kind' => 'devoir', 'title' => 'Devoir 1', 'weight' => 1, 'max_score' => 20], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/grades', $this->tokens['teacher'], ['assessment_id' => $assessment, 'person_id' => $family['learner'], 'value' => 14], $this->school);

        // Messagerie.
        $this->ok('POST', '/conversations', $this->tokens['parent'], ['subject' => 'Suivi', 'kind' => 'teacher_parent', 'learner_id' => $family['learner'], 'participant_ids' => [$teacher['person_id']], 'body' => 'Bonjour'], $this->school, [], 201);

        // Client B.
        $other = $this->createClient('Client B', 'Boris', 'Intrus');
        $this->tenantB = $other['tenant_id'];
        $this->tokens['adminB'] = $this->signIn($other['login'], $other['password']);
        $this->schoolB = (string) $this->ok('POST', '/establishments', $this->tokens['adminB'], ['name' => 'Collège B', 'type' => 'secondary'], null, [], 201)['data']['id'];
        $this->ok('POST', '/people', $this->tokens['adminB'], ['first_name' => 'Secret', 'last_name' => 'Intrus', 'birth_date' => '2012-01-01'], $this->schoolB, [], 201);

        $this->tenantAIds = $this->collectTenantIds($this->tenant);
        $this->sensitiveIds = $this->collectTenantIds($this->tenant, ['people', 'users', 'enrollments', 'invoices', 'payments', 'receipts', 'grades', 'attendance_records', 'attendance_sessions', 'documents', 'conversations', 'staff_members', 'person_relations', 'person_documents']);
        unset($this->sensitiveIds[$this->known['apprenant_person']], $this->sensitiveIds[$this->known['apprenant_user']]);
    }

    /** @return array<string, true> */
    /** @param list<string>|null $only */
    private function collectTenantIds(string $tenant, ?array $only = null): array
    {
        $pdo = $this->pdo();
        $ids = $only === null ? [$tenant => true] : [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $columns = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC), 'name');
            if (!in_array('tenant_id', $columns, true) || !in_array('id', $columns, true) || ($only !== null && !in_array($table, $only, true))) {
                continue;
            }
            $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE tenant_id = ?');
            $stmt->execute([$tenant]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                if (is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * Toutes les routes déclarées (RouteRegistry), une ligne par route.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    public static function allRoutes(): array
    {
        $kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['api_prefix' => '/api/v1', 'timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log'],
        ]));
        $rows = [];
        foreach ($kernel->routes()->all() as $route) {
            $rows[$route['method'] . ' ' . $route['path']] = [$route['method'], $route['path'], (bool) $route['options']['auth'], (bool) $route['options']['tenant']];
        }

        return $rows;
    }

    /** @return array<string, mixed> chemins de contracts/openapi.yaml et openapi.d fusionnés */
    public static function openApi(): array
    {
        static $spec = null;
        if ($spec === null) {
            $root = dirname(__DIR__, 3) . '/specs/001-socle-plateforme/contracts';
            $spec = Yaml::parseFile($root . '/openapi.yaml');
            foreach (glob($root . '/openapi.d/*.yaml') ?: [] as $fragment) {
                $spec['paths'] = array_merge($spec['paths'], (array) (Yaml::parseFile($fragment)['paths'] ?? []));
            }
        }

        return $spec;
    }

    /** @var array<string, string> */
    private array $resolved = [];

    /**
     * Remplace chaque paramètre de chemin par un identifiant réel du client A (premier élément de la liste parente
     * lue par l'administrateur), ou par une valeur connue ; « x-inconnu » à défaut.
     */
    protected function concretePath(string $path): string
    {
        $prefix = '/api/v1';
        $relative = substr($path, strlen($prefix));
        while (preg_match('/\{([^}]+)\}/', $relative, $m, PREG_OFFSET_CAPTURE) === 1) {
            $name = $m[1][0];
            $offset = (int) $m[0][1];
            $collection = rtrim(substr($relative, 0, $offset), '/');
            $value = match (true) {
                $name === 'token' => $this->known['token'],
                $name === 'reference' => $this->known['reference'],
                $name === 'provider' => 'orange_money',
                $name === 'kind' && str_starts_with($collection, '/dashboards') => 'director',
                $name === 'kind' => 'journal',
                $collection === '/attendance/students' => $this->known['learner'],
                default => $this->firstId($collection),
            };
            $relative = substr($relative, 0, $offset) . $value . substr($relative, $offset + strlen($m[0][0]));
        }

        return $prefix . $relative;
    }

    private function firstId(string $collection): string
    {
        if (!isset($this->resolved[$collection])) {
            [$response, $json] = $this->api('GET', $collection . '?per_page=1', $this->admin, null, $this->school);
            $id = $response->getStatusCode() === 200 ? ($json['data'][0]['id'] ?? null) : null;
            $this->resolved[$collection] = is_string($id) ? $id : 'x-inconnu';
        }

        return $this->resolved[$collection];
    }

    /** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>} appel sur un chemin déjà préfixé */
    protected function raw(string $method, string $fullPath, ?string $token, ?string $establishment, ?string $body = null, array $headers = []): array
    {
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $headers += ['Accept-Language' => 'fr', 'User-Agent' => 'Recette contrat'];

        return $this->send(new \Nyholm\Psr7\ServerRequest($method, $fullPath, $headers, $body ?? ''));
    }

    /** Vérifie le format RFC 9457 d'une réponse d'erreur. */
    protected function assertProblem(\Psr\Http\Message\ResponseInterface $response, string $context): void
    {
        self::assertStringStartsWith('application/problem+json', $response->getHeaderLine('Content-Type'), $context . ' : type de contenu');
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json, $context . ' : corps JSON');
        foreach (['type', 'title', 'status', 'detail', 'trace_id'] as $key) {
            self::assertArrayHasKey($key, $json, $context . ' : champ « ' . $key . ' » absent');
        }
        self::assertSame($response->getStatusCode(), $json['status'], $context . ' : status du corps');
        self::assertMatchesRegularExpression('/^[0-9A-Za-z\-]{16,64}$/', (string) $json['trace_id'], $context . ' : trace_id');
    }

    protected function assertSecurityHeaders(\Psr\Http\Message\ResponseInterface $response, string $context): void
    {
        foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer'] as $header => $value) {
            self::assertSame($value, $response->getHeaderLine($header), $context . ' : en-tête ' . $header);
        }
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'), $context . ' : CSP');
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), $context . ' : Cache-Control');
    }

    /** Aucun identifiant du client A ne doit apparaître dans une réponse destinée à B. */
    protected function assertNoTenantALeak(\Psr\Http\Message\ResponseInterface $response, string $context): void
    {
        $body = (string) $response->getBody();
        if (preg_match_all('/[0-9A-HJKMNP-TV-Z]{26}/', $body, $m) > 0) {
            $leaked = array_values(array_filter(array_unique($m[0]), fn (string $id): bool => isset($this->tenantAIds[$id])));
            self::assertSame([], $leaked, $context . ' : identifiants du client A renvoyés au client B');
        }
        self::assertStringNotContainsString('Nadia', $body, $context . ' : donnée personnelle du client A');
    }
}
