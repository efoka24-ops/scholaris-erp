<?php

declare(strict_types=1);

/**
 * Profil SQL en processus sur la base de charge : rejoue les requêtes HTTP des scénarios, journalise chaque instruction
 * exécutée (texte, paramètres, durée), repère les N+1 (même instruction répétée dans une seule requête HTTP) puis passe
 * EXPLAIN QUERY PLAN sur chaque instruction distincte et signale les parcours complets (SCAN) et tris temporaires.
 * Imprime un JSON. SQLite ≠ MySQL : les plans indiquent les index manquants, pas les temps de production.
 *
 *   php tests/Load/explain.php
 */

use Nyholm\Psr7\ServerRequest;
use Scholaris\Core\Database\ConnectionFactory;
use Tests\Load\Support\LoadEnvironment;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class SqlProfile
{
    /** @var list<array{sql: string, params: array<int|string, mixed>, ms: float}> */
    public static array $log = [];
}

class LoggingStatement extends PDOStatement
{
    /** @var array<int|string, mixed> */
    private array $bound = [];

    protected function __construct()
    {
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    public function execute(?array $params = null): bool
    {
        $t = hrtime(true);
        $ok = parent::execute($params);
        SqlProfile::$log[] = ['sql' => $this->queryString, 'params' => $params ?? $this->bound, 'ms' => (hrtime(true) - $t) / 1e6];

        return $ok;
    }
}

$work = LoadEnvironment::workDir();
$fixture = json_decode((string) file_get_contents($work . '/fixture.json'), true);
$kernel = LoadEnvironment::kernel($work . '/charge.sqlite');
$pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggingStatement::class, []]);

$est = $fixture['establishment'];
$h = static fn (string $who): array => ['Authorization' => 'Bearer ' . $fixture['tokens'][$who], 'X-Establishment-Id' => $est, 'Content-Type' => 'application/json'];
$requests = [
    'GET /people' => ['GET', '/api/v1/people?per_page=50&cursor=' . ($fixture['people_cursors'][10] ?? ''), $h('admin'), null],
    'GET /people?filter[q]' => ['GET', '/api/v1/people?per_page=20&filter[q]=' . rawurlencode((string) $fixture['last_names'][0]), $h('admin'), null],
    'GET /invoices' => ['GET', '/api/v1/invoices?per_page=50', $h('admin'), null],
    'GET /me/contexts' => ['GET', '/api/v1/me/contexts', $h('admin'), null],
    'GET /enrollments?filter[structure_id]' => ['GET', '/api/v1/enrollments?filter[structure_id]=' . $fixture['classes'][3], $h('admin'), null],
    'GET /portal/family (garant)' => ['GET', '/api/v1/billing/families/FAM-0001/balance', $h('admin'), null],
    'POST /cash/receipts' => ['POST', '/api/v1/cash/receipts', $h('cashier') + ['Idempotency-Key' => 'explain-' . bin2hex(random_bytes(6))], json_encode(['amount_xaf' => 1000, 'learner_id' => $fixture['learner_sample'][0]])],
    'POST /grades/batch' => ['POST', '/api/v1/grades/batch', $h('admin'), json_encode(['assessment_id' => $fixture['assessment'], 'entries' => array_map(static fn (string $p): array => ['person_id' => $p, 'value' => 12], $fixture['class1_learners'])])],
    'GET /grade-sheets' => ['GET', '/api/v1/grade-sheets?structure_id=' . $fixture['classes'][0] . '&period_id=' . ($fixture['periods'][0] ?? ''), $h('admin'), null],
    'GET /dashboards/director' => ['GET', '/api/v1/dashboards/director', $h('admin'), null],
];

$tables = [];
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $tables[$t] = (int) $pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
}

$perRequest = [];
$distinct = [];
foreach ($requests as $label => [$method, $path, $headers, $body]) {
    SqlProfile::$log = [];
    $request = new ServerRequest($method, $path, $headers, $body ?? '');
    parse_str($request->getUri()->getQuery(), $query);
    $t = hrtime(true);
    $response = $kernel->handle($request->withQueryParams($query));
    $ms = (hrtime(true) - $t) / 1e6;
    $counts = [];
    foreach (SqlProfile::$log as $entry) {
        $norm = preg_replace('/\s+/', ' ', trim($entry['sql']));
        $counts[$norm] = ($counts[$norm] ?? 0) + 1;
        $distinct[$norm] ??= ['sql' => $norm, 'params' => $entry['params'], 'calls' => 0, 'total_ms' => 0.0, 'requests' => []];
        $distinct[$norm]['calls']++;
        $distinct[$norm]['total_ms'] += $entry['ms'];
        $distinct[$norm]['requests'][$label] = true;
    }
    arsort($counts);
    $repeated = array_filter($counts, static fn (int $n): bool => $n >= 10);
    $perRequest[$label] = [
        'status' => $response->getStatusCode(), 'ms' => round($ms, 1), 'queries' => count(SqlProfile::$log),
        'sql_ms' => round(array_sum(array_column(SqlProfile::$log, 'ms')), 1),
        'n_plus_one' => array_map(static fn (string $sql, int $n): array => ['times' => $n, 'sql' => substr($sql, 0, 220)], array_keys($repeated), $repeated),
    ];
}

$findings = [];
foreach ($distinct as $norm => $info) {
    if (!preg_match('/^\s*(SELECT|UPDATE|DELETE)/i', $norm)) {
        continue;
    }
    try {
        $stmt = $pdo->prepare('EXPLAIN QUERY PLAN ' . $info['sql']);
        $params = $info['params'];
        $stmt->execute($params === [] ? null : $params);
        $plan = array_map(static fn (array $r): string => (string) $r['detail'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $plan = ['(plan indisponible : ' . $e->getMessage() . ')'];
    }
    $issues = [];
    foreach ($plan as $line) {
        if (preg_match('/^SCAN (\w+)(?! USING (COVERING )?INDEX)/', $line, $m) === 1 && ($tables[$m[1]] ?? 0) >= 500) {
            $issues[] = 'parcours complet de ' . $m[1] . ' (' . $tables[$m[1]] . ' lignes)';
        }
        if (str_contains($line, 'USE TEMP B-TREE')) {
            $issues[] = 'tri temporaire (' . $line . ')';
        }
    }
    if ($issues !== []) {
        $findings[] = ['sql' => substr($norm, 0, 400), 'calls' => $info['calls'], 'total_ms' => round($info['total_ms'], 1), 'requests' => array_keys($info['requests']), 'plan' => $plan, 'issues' => array_values(array_unique($issues))];
    }
}
usort($findings, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

echo json_encode(['tables' => array_filter($tables, static fn (int $n): bool => $n >= 500), 'requests' => $perRequest, 'slow_or_unindexed' => $findings], JSON_UNESCAPED_UNICODE);
