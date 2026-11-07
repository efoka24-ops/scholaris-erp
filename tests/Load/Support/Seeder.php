<?php

declare(strict_types=1);

namespace Tests\Load\Support;

use Nyholm\Psr7\ServerRequest;
use PDO;
use RuntimeException;
use Scholaris\Core\Console\CreateAdministrator;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Application\PasswordHasher;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Remplit une base SQLite fichier jetable. Toute la structure passe par l'API réelle (en processus) : client, établissement,
 * quatre années, périodes, 100 classes autorisées, comptes, emploi du temps, grille de frais, un apprenant modèle complet
 * (fiche, garant, pièces, inscription validée, facture). Le volume (personnes, inscriptions, factures, tranches) est ensuite
 * produit en SQL par copie des lignes du modèle, colonne par colonne : mêmes formes que l'API, en quelques secondes.
 */
final class Seeder
{
    public const PASSWORD = 'Charge-Locale-2026!';

    private Kernel $kernel;
    private PDO $pdo;
    private string $token = '';
    private string $school = '';

    public function __construct(private readonly string $database)
    {
    }

    /** @return array<string, mixed> description du jeu (identifiants, jetons) pour les scénarios */
    public function seed(int $learners, int $years = 4): array
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->database . $suffix);
        }
        touch($this->database);
        $this->kernel = LoadEnvironment::kernel($this->database);
        $this->pdo = $this->kernel->container()->get(ConnectionFactory::class)->pdo();
        $this->kernel->container()->get(Migrator::class)->migrate();

        $admin = (new CreateAdministrator($this->kernel->container()->get(ConnectionFactory::class)))->create('Client charge', 'Awa', 'Admin', '+237690000001');
        $this->setPassword($admin['tenant_id'], $admin['user_id']);
        $this->token = $this->login('+237690000001');
        $tenant = $admin['tenant_id'];

        $this->school = (string) $this->api('POST', '/establishments', ['name' => 'Lycée de charge', 'type' => 'secondary'], false)['data']['id'];
        $yearIds = [];
        for ($y = 2027 - $years; $y < 2027; $y++) {
            $yearIds[] = (string) $this->api('POST', '/academic-years', ['label' => $y . '-' . ($y + 1), 'starts_on' => $y . '-09-01', 'ends_on' => ($y + 1) . '-06-30'])['data']['id'];
        }
        $current = (string) end($yearIds);
        $periods = [];
        foreach ([[1, '2026-09-01', '2026-12-18'], [2, '2027-01-04', '2027-03-26'], [3, '2027-04-06', '2027-06-30']] as [$o, $from, $to]) {
            $periods[] = (string) $this->api('POST', '/periods', ['academic_year_id' => $current, 'kind' => 'trimester', 'ordinal' => $o, 'starts_on' => $from, 'ends_on' => $to])['data']['id'];
        }
        $classes = [];
        for ($c = 1; $c <= 100; $c++) {
            $id = (string) $this->api('POST', '/structures', ['kind' => 'class', 'name' => sprintf('Classe %03d', $c), 'capacity' => 60])['data']['id'];
            $this->api('POST', '/authorizations', ['structure_id' => $id, 'reference' => 'ARR-' . $c, 'authority' => 'MINESEC', 'valid_from' => '2020-01-01', 'valid_to' => '2030-12-31']);
            $classes[] = $id;
        }

        // Comptes : enseignant et caissier (mot de passe posé directement, sans SMS).
        $teacher = $this->account($tenant, 'Tano', 'Prof', '+237690000002', 'enseignant');
        $cashier = $this->account($tenant, 'Cathy', 'Caisse', '+237690000003', 'caissier');
        $this->api('POST', '/staff', ['person_id' => $teacher['person_id'], 'kind' => 'permanent']);
        $slots = [];
        for ($i = 0; $i < 40; $i++) {
            $weekday = intdiv($i, 8) + 1;
            $hour = 7 + ($i % 8);
            $slots[] = ['id' => (string) $this->api('POST', '/timetable-slots', [
                'subject_label' => 'Mathématiques', 'teacher_person_id' => $teacher['person_id'], 'structure_ids' => [$classes[$i]], 'weekday' => $weekday,
                'start_time' => sprintf('%02d:00', $hour), 'end_time' => sprintf('%02d:00', $hour + 1), 'valid_from' => '2026-09-01',
            ])['data']['id'], 'weekday' => $weekday, 'class' => $classes[$i]];
        }
        $item = (string) $this->api('POST', '/fee-items', ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 300000])['data']['id'];
        $this->api('POST', '/fee-schedules', ['fee_item_id' => $item, 'academic_year_id' => $current, 'amount_xaf' => 300000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 150000], ['due_on' => '2027-01-15', 'amount' => 150000]]]);

        // Apprenant modèle par l'API.
        $learner = (string) $this->api('POST', '/people', ['first_name' => 'Modèle', 'last_name' => 'Apprenant', 'birth_date' => '2011-01-01', 'gender' => 'female'])['data']['id'];
        $guarantor = (string) $this->api('POST', '/people', ['first_name' => 'Modèle', 'last_name' => 'Garant', 'phone' => '+237670000000'])['data']['id'];
        $this->api('POST', '/people/' . $learner . '/relations', ['related_person_id' => $guarantor, 'kind' => 'financial_guarantor']);
        $enrollment = (string) $this->api('POST', '/enrollments', ['person_id' => $learner, 'structure_id' => $classes[0], 'academic_year_id' => $current])['data']['id'];
        $this->api('POST', '/enrollments/' . $enrollment . '/validate', []);
        $invoice = (string) $this->api('POST', '/invoices', ['enrollment_id' => $enrollment])['data']['id'];

        $this->bulk($tenant, $learner, $guarantor, $enrollment, $invoice, $learners, $yearIds, $classes);

        // Évaluation de la classe 1 (saisie par lot) et caisse ouverte.
        $subject = (string) $this->api('POST', '/subjects', ['code' => 'MATH', 'name' => 'Mathématiques', 'kind' => 'subject', 'coefficient' => 2, 'credits' => 0])['data']['id'];
        $assessment = (string) $this->api('POST', '/assessments', ['subject_id' => $subject, 'structure_id' => $classes[0], 'period_id' => $periods[0], 'academic_year_id' => $current, 'kind' => 'devoir', 'title' => 'Devoir 1', 'weight' => 1, 'max_score' => 20])['data']['id'];
        $this->api('POST', '/cash-sessions', ['float_xaf' => 10000], true, $cashier['token']);

        $stmt = $this->pdo->prepare("SELECT person_id FROM enrollments WHERE tenant_id = ? AND structure_id = ? AND academic_year_id = ? AND status = 'active'");
        $stmt->execute([$tenant, $classes[0], $current]);
        $class1 = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $sample = $this->pdo->query("SELECT person_id FROM enrollments WHERE status = 'active' ORDER BY random() LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
        $names = $this->pdo->query("SELECT DISTINCT last_name FROM people WHERE last_name LIKE 'Nom%' LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
        $cursors = [];
        $cursor = null;
        for ($page = 0; $page < 40; $page++) {
            $json = $this->api('GET', '/people?per_page=50' . ($cursor !== null ? '&cursor=' . $cursor : ''));
            $cursor = $json['meta']['next_cursor'] ?? null;
            if (!is_string($cursor)) {
                break;
            }
            $cursors[] = $cursor;
        }
        $counts = [];
        foreach (['people', 'enrollments', 'invoices', 'installments', 'person_relations'] as $table) {
            $counts[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }

        return [
            'tenant' => $tenant, 'establishment' => $this->school, 'current_year' => $current, 'periods' => $periods, 'classes' => $classes, 'slots' => $slots,
            'assessment' => $assessment, 'class1_learners' => $class1, 'learner_sample' => $sample, 'last_names' => $names, 'people_cursors' => $cursors,
            'tokens' => ['admin' => $this->token, 'teacher' => $teacher['token'], 'cashier' => $cashier['token']],
            'login' => ['identifier' => '+237690000001', 'password' => self::PASSWORD], 'counts' => $counts,
        ];
    }

    /** @param list<string> $yearIds @param list<string> $classes */
    private function bulk(string $tenant, string $learner, string $guarantor, string $enrollment, string $invoice, int $count, array $yearIds, array $classes): void
    {
        $row = fn (string $table, string $id): array => $this->pdo->query("SELECT * FROM {$table} WHERE id = " . $this->pdo->quote($id))->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException($table . ' modèle introuvable');
        $templates = [
            'learner' => $row('people', $learner), 'guarantor' => $row('people', $guarantor),
            'enrollment' => $row('enrollments', $enrollment), 'invoice' => $row('invoices', $invoice),
        ];
        $relation = $this->pdo->query('SELECT * FROM person_relations WHERE person_id = ' . $this->pdo->quote($learner))->fetch(PDO::FETCH_ASSOC);
        $installments = $this->pdo->query('SELECT * FROM installments WHERE invoice_id = ' . $this->pdo->quote($invoice) . ' ORDER BY seq_no')->fetchAll(PDO::FETCH_ASSOC);
        $current = (string) end($yearIds);

        $insert = [];
        $prepare = function (string $table, array $template) use (&$insert): \PDOStatement {
            return $insert[$table] ??= $this->pdo->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($template)), implode(', ', array_fill(0, count($template), '?'))));
        };
        $put = static function (\PDOStatement $stmt, array $template, array $overrides): void {
            $stmt->execute(array_values(array_replace($template, $overrides)));
        };

        $this->pdo->beginTransaction();
        $first = ['Awa', 'Boris', 'Chloé', 'Didier', 'Esther', 'Franck', 'Grâce', 'Hervé', 'Inès', 'Jules'];
        $number = 100;
        for ($i = 1; $i < $count; $i++) {
            $personId = (string) new Ulid();
            $put($prepare('people', $templates['learner']), $templates['learner'], ['id' => $personId, 'first_name' => $first[$i % 10], 'last_name' => 'Nom' . str_pad((string) ($i % 997), 3, '0', STR_PAD_LEFT), 'birth_date' => sprintf('20%02d-%02d-%02d', 8 + $i % 6, 1 + $i % 12, 1 + $i % 28)]);
            if ($i % 2 === 1) {
                $guarantorId = (string) new Ulid();
                $put($prepare('people', $templates['guarantor']), $templates['guarantor'], ['id' => $guarantorId, 'first_name' => 'Parent', 'last_name' => 'Nom' . str_pad((string) ($i % 997), 3, '0', STR_PAD_LEFT), 'phone' => sprintf('+2376%08d', 10000000 + $i)]);
            }
            if (is_array($relation) && isset($guarantorId)) {
                $put($prepare('person_relations', $relation), $relation, ['id' => (string) new Ulid(), 'person_id' => $personId, 'related_person_id' => $guarantorId]);
            }
            foreach ($yearIds as $y => $yearId) {
                $isCurrent = $yearId === $current;
                $enrollmentId = (string) new Ulid();
                $put($prepare('enrollments', $templates['enrollment']), $templates['enrollment'], [
                    'id' => $enrollmentId, 'person_id' => $personId, 'academic_year_id' => $yearId, 'structure_id' => $classes[$i % 100],
                    'status' => $isCurrent ? 'active' : 'graduated', 'internal_number' => sprintf('MAT-%d-%05d', 2023 + $y, ++$number),
                ]);
                $invoiceId = (string) new Ulid();
                $put($prepare('invoices', $templates['invoice']), $templates['invoice'], [
                    'id' => $invoiceId, 'enrollment_id' => $enrollmentId, 'learner_id' => $personId, 'academic_year_id' => $yearId,
                    'status' => $isCurrent ? $templates['invoice']['status'] : 'paid',
                    'paid_xaf' => $isCurrent ? 0 : $templates['invoice']['net_xaf'], 'balance_xaf' => $isCurrent ? $templates['invoice']['net_xaf'] : 0,
                ]);
                foreach ($installments as $installment) {
                    $put($prepare('installments', $installment), $installment, [
                        'id' => (string) new Ulid(), 'invoice_id' => $invoiceId, 'line_id' => (string) new Ulid(), 'learner_id' => $personId,
                        'paid_xaf' => $isCurrent ? 0 : $installment['amount_xaf'],
                    ]);
                }
            }
        }
        $this->pdo->commit();
        $this->pdo->exec('ANALYZE');
    }

    /** @return array{person_id: string, token: string} */
    private function account(string $tenant, string $first, string $last, string $phone, string $role): array
    {
        $created = $this->api('POST', '/users', ['first_name' => $first, 'last_name' => $last, 'phone' => $phone]);
        $this->api('POST', '/role-assignments', ['person_id' => $created['data']['person_id'], 'role' => $role, 'scope_type' => 'establishment', 'scope_id' => $this->school]);
        $this->setPassword($tenant, (string) $created['data']['user_id']);
        $this->pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$created['data']['user_id']]);

        return ['person_id' => (string) $created['data']['person_id'], 'token' => $this->login($phone)];
    }

    private function setPassword(string $tenant, string $userId): void
    {
        $c = $this->kernel->container();
        $c->get(PdoUserRepository::class)->setPassword($tenant, $userId, $c->get(PasswordHasher::class)->hash(self::PASSWORD), $c->get(Moment::class)->iso());
    }

    private function login(string $identifier): string
    {
        $json = $this->api('POST', '/auth/login', ['identifier' => $identifier, 'password' => self::PASSWORD], false, null);

        return (string) $json['token'];
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    private function api(string $method, string $path, ?array $body = null, bool $establishment = true, ?string $token = '', array $headers = []): array
    {
        $token = $token === '' ? $this->token : $token;
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($establishment && $this->school !== '') {
            $headers['X-Establishment-Id'] = $this->school;
        }
        $headers += ['Content-Type' => 'application/json', 'User-Agent' => 'Amorcage charge'];
        $request = new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        parse_str($request->getUri()->getQuery(), $query);
        $response = $this->kernel->handle($request->withQueryParams($query));
        $status = $response->getStatusCode();
        if ($status >= 300) {
            throw new RuntimeException($method . ' ' . $path . ' -> ' . $status . ' ' . substr((string) $response->getBody(), 0, 500));
        }

        return (array) json_decode((string) $response->getBody(), true);
    }
}
