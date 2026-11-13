<?php

declare(strict_types=1);

/**
 * Test de charge LOCAL et exécutable (pas de réseau externe) :
 *  1. amorce une base SQLite fichier jetable (tests/Load/.work/charge.sqlite) : 5 000 apprenants, 20 000 inscriptions et factures ;
 *  2. démarre N serveurs PHP intégrés (php -S, opcache activé) sur 127.0.0.1:8101.. ; PHP_CLI_SERVER_WORKERS n'existe pas
 *     sous Windows, d'où plusieurs serveurs mono-processus derrière une répartition circulaire côté client ;
 *  3. mesure, par scénario et par palier de concurrence (10, 25, 50 utilisateurs), le débit, p50/p95/p99 et le taux d'erreur,
 *     avec un client curl_multi ;
 *  4. arrête les serveurs, puis mesure en processus séparés le lot de 200 documents et les plans de requêtes (EXPLAIN).
 *
 *   php tests/Load/run.php [--learners=5000] [--levels=10,25,50] [--seconds=8] [--servers=4] [--skip-seed]
 *
 * Résultats : tests/Load/.work/results.json (et résumé à l'écran). SQLite local n'est PAS représentatif de MySQL en production.
 */

use Tests\Load\Support\Seeder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['learners::', 'levels::', 'seconds::', 'servers::', 'skip-seed', 'only::']);
$learners = (int) ($options['learners'] ?? 5000);
$levels = array_map('intval', explode(',', (string) ($options['levels'] ?? '10,25,50')));
$seconds = (float) ($options['seconds'] ?? 8);
$serverCount = max(1, (int) ($options['servers'] ?? 4));
$only = isset($options['only']) ? explode(',', (string) $options['only']) : null;

$work = Tests\Load\Support\LoadEnvironment::workDir();
$db = $work . '/charge.sqlite';
$fixtureFile = $work . '/fixture.json';
$php = PHP_BINARY;

// 1. Amorçage.
if (!isset($options['skip-seed']) || !is_file($fixtureFile)) {
    $t0 = microtime(true);
    $fixture = (new Seeder($db))->seed($learners);
    file_put_contents($fixtureFile, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    printf("Amorçage : %.1f s, %s\n", microtime(true) - $t0, json_encode($fixture['counts']));
    gc_collect_cycles();
} else {
    $fixture = json_decode((string) file_get_contents($fixtureFile), true);
}

// 2. Serveurs.
$servers = [];
$ports = [];
try {
    for ($i = 0; $i < $serverCount; $i++) {
        $port = 8101 + $i;
        $env = array_merge(getenv(), ['LOADTEST_DB' => $db]);
        $proc = proc_open(
            [$php, '-d', 'zend_extension=opcache', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', '-d', 'memory_limit=512M', '-S', '127.0.0.1:' . $port, 'tests/Load/server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $work . '/server-' . $port . '.log', 'w'], 2 => ['file', $work . '/server-' . $port . '.log', 'a']],
            $pipes,
            dirname(__DIR__, 2),
            $env,
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('Démarrage du serveur ' . $port . ' impossible.');
        }
        $servers[] = $proc;
        $ports[] = $port;
    }
    foreach ($ports as $port) {
        $deadline = microtime(true) + 20;
        do {
            $ch = curl_init('http://127.0.0.1:' . $port . '/api/v1/health');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
            curl_exec($ch);
            $ok = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
            curl_close($ch);
            if (!$ok) {
                usleep(200_000);
            }
        } while (!$ok && microtime(true) < $deadline);
        if (!$ok) {
            throw new RuntimeException('Serveur ' . $port . ' injoignable (voir ' . $work . '/server-' . $port . '.log).');
        }
    }
    printf("Serveurs prêts : %s\n\n", implode(', ', $ports));

    // 3. Scénarios.
    $est = $fixture['establishment'];
    $auth = static fn (string $who): array => ['Authorization: Bearer ' . $fixture['tokens'][$who], 'X-Establishment-Id: ' . $est, 'Accept: application/json'];
    $pick = static fn (array $list): mixed => $list[array_rand($list)];
    $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Douala'));
    $combos = [];
    foreach ($fixture['slots'] as $slot) {
        for ($d = new DateTimeImmutable('2026-09-07'); $d <= $today; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') === $slot['weekday']) {
                $combos[] = [$slot['id'], $d->format('Y-m-d')];
            }
        }
    }
    shuffle($combos);
    $ulid = static fn (): string => (string) new Scholaris\Core\Support\Ulid();

    $scenarios = [
        'GET /people (page de 50, curseur)' => ['read' => true, 'expect' => [200], 'make' => static fn (): array => ['GET', '/api/v1/people?per_page=50' . (random_int(0, 3) > 0 && $fixture['people_cursors'] !== [] ? '&cursor=' . $pick($fixture['people_cursors']) : ''), $auth('admin'), null]],
        'GET /people?filter[q] (recherche)' => ['read' => true, 'expect' => [200], 'make' => static fn (): array => ['GET', '/api/v1/people?per_page=20&filter[q]=' . rawurlencode((string) $pick($fixture['last_names'])), $auth('admin'), null]],
        'GET /invoices (page de 50)' => ['read' => true, 'expect' => [200], 'make' => static fn (): array => ['GET', '/api/v1/invoices?per_page=50', $auth('admin'), null]],
        'GET /me/contexts (tuiles et indicateurs)' => ['read' => true, 'expect' => [200], 'make' => static fn (): array => ['GET', '/api/v1/me/contexts', $auth('admin'), null]],
        'POST /auth/login (Argon2id)' => ['read' => false, 'expect' => [200], 'make' => static fn (): array => ['POST', '/api/v1/auth/login', ['Content-Type: application/json'], json_encode($fixture['login'])]],
        'POST /cash/receipts (Idempotency-Key)' => ['read' => false, 'expect' => [201], 'make' => static fn (): array => ['POST', '/api/v1/cash/receipts', array_merge($auth('cashier'), ['Content-Type: application/json', 'Idempotency-Key: charge-' . $ulid()]), json_encode(['amount_xaf' => 1000, 'learner_id' => $pick($fixture['learner_sample'])])]],
        'POST /attendance-sessions/sync (appel)' => ['read' => false, 'expect' => [200], 'limit' => intdiv(count($combos), max(1, count($levels))), 'make' => static function () use (&$combos, $auth, $ulid): array {
            [$slot, $date] = array_pop($combos) ?? ['epuise', '2026-09-07'];

            return ['POST', '/api/v1/attendance-sessions/sync', array_merge($auth('teacher'), ['Content-Type: application/json']), json_encode(['sessions' => [['client_uuid' => $ulid(), 'slot_id' => $slot, 'date' => $date, 'validate' => false, 'records' => []]]])];
        }],
        'POST /grades/batch (50 notes)' => ['read' => false, 'expect' => [200, 201], 'make' => static fn (): array => ['POST', '/api/v1/grades/batch', array_merge($auth('admin'), ['Content-Type: application/json']), json_encode(['assessment_id' => $fixture['assessment'], 'entries' => array_map(static fn (string $p): array => ['person_id' => $p, 'value' => random_int(0, 40) / 2], $fixture['class1_learners'])])]],
    ];

    $results = [];
    foreach ($scenarios as $name => $scenario) {
        if ($only !== null && !in_array(array_search($name, array_keys($scenarios), true), array_map('intval', $only), true)) {
            continue;
        }
        foreach ($levels as $concurrency) {
            $results[] = ['scenario' => $name, 'concurrency' => $concurrency] + runLevel($scenario, $concurrency, $seconds, $ports);
            $r = end($results);
            printf("%-42s c=%-3d n=%-5d %6.1f req/s  p50 %7.1f  p95 %7.1f  p99 %7.1f ms  erreurs %5.2f %% %s\n", $name, $concurrency, $r['requests'], $r['throughput_rps'], $r['p50_ms'], $r['p95_ms'], $r['p99_ms'], $r['error_rate_pct'], $r['sample_error'] ?? '');
        }
    }
} finally {
    foreach ($servers as $proc) {
        $status = proc_get_status($proc);
        proc_terminate($proc);
        if (PHP_OS_FAMILY === 'Windows' && $status['running']) {
            exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
        }
        proc_close($proc);
    }
    echo "\nServeurs arrêtés.\n";
}

// 4. Lot de 200 documents et plans de requêtes, en processus séparés (mémoire mesurée proprement).
$documents = json_decode((string) shell_exec(escapeshellarg($php) . ' -d memory_limit=1G ' . escapeshellarg(__DIR__ . '/documents-batch.php') . ' 200'), true);
printf("\nLot de 200 documents : %s\n", json_encode($documents, JSON_UNESCAPED_UNICODE));
$explain = json_decode((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/explain.php')), true);

file_put_contents($work . '/results.json', json_encode([
    'measured_at' => date(DATE_ATOM), 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY . ' ' . php_uname('r'), 'cpu' => (int) getenv('NUMBER_OF_PROCESSORS'),
    'servers' => $serverCount, 'seconds_per_level' => $seconds, 'dataset' => $fixture['counts'], 'levels' => $results, 'documents_batch' => $documents, 'explain' => $explain,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Résultats : {$work}/results.json\n";

/**
 * @param array{expect: list<int>, make: callable, limit?: int} $scenario
 * @param list<int> $ports
 * @return array<string, mixed>
 */
function runLevel(array $scenario, int $concurrency, float $seconds, array $ports): array
{
    $multi = curl_multi_init();
    $latencies = [];
    $errors = 0;
    $sample = null;
    $started = 0;
    $limit = $scenario['limit'] ?? PHP_INT_MAX;
    $begin = microtime(true);
    $deadline = $begin + $seconds;
    $next = 0;
    $launch = static function () use (&$started, &$next, $multi, $scenario, $ports): void {
        [$method, $path, $headers, $body] = ($scenario['make'])();
        $ch = curl_init('http://127.0.0.1:' . $ports[$next++ % count($ports)] . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge($headers, ['User-Agent: Banc de charge', 'Accept-Language: fr']), CURLOPT_TIMEOUT => 60]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_multi_add_handle($multi, $ch);
        $started++;
    };
    for ($i = 0; $i < $concurrency && $started < $limit; $i++) {
        $launch();
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $ch = $info['handle'];
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $latencies[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            if ($info['result'] !== CURLE_OK || !in_array($code, $scenario['expect'], true)) {
                $errors++;
                $sample ??= $code . ' ' . substr((string) curl_multi_getcontent($ch), 0, 160) . curl_error($ch);
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            if (microtime(true) < $deadline && $started < $limit) {
                $launch();
            }
        }
    } while ($running > 0 || (microtime(true) < $deadline && $started < $limit && count($latencies) < $started));
    $elapsed = microtime(true) - $begin;
    curl_multi_close($multi);
    sort($latencies);
    $q = static fn (float $p): float => $latencies === [] ? 0.0 : $latencies[min(count($latencies) - 1, (int) ceil($p * count($latencies)) - 1)];

    return [
        'requests' => count($latencies), 'elapsed_s' => round($elapsed, 2), 'throughput_rps' => round(count($latencies) / max($elapsed, 0.001), 1),
        'p50_ms' => round($q(0.50), 1), 'p95_ms' => round($q(0.95), 1), 'p99_ms' => round($q(0.99), 1), 'max_ms' => round($latencies === [] ? 0 : end($latencies), 1),
        'errors' => $errors, 'error_rate_pct' => round(100 * $errors / max(1, count($latencies)), 2), 'sample_error' => $sample,
    ];
}
