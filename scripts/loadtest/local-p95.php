<?php

declare(strict_types=1);

/**
 * Mesure locale du p95 (SQLite en mémoire, sans réseau). NE REMPLACE PAS le test k6 (scripts/loadtest/k6-api.js) :
 * il ne couvre ni le serveur web, ni MySQL, ni le réseau.
 *
 *   php scripts/loadtest/local-p95.php [requêtes=500] [lignes=500]
 *
 * Code de sortie 1 si un p95 dépasse l'objectif de 300 ms ou si une requête échoue.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tests\Support\Performance\LatencyProbe;

$requests = max(20, (int) ($argv[1] ?? 500));
$rows = max(1, (int) ($argv[2] ?? 500));
$probe = new LatencyProbe($rows);

$scenarios = [
    'GET /api/v1/health (public)' => ['/api/v1/health', false],
    'GET /api/v1/_test/things (authentifié, cloisonné, ' . $rows . ' lignes)' => ['/api/v1/_test/things', true],
];

$failed = false;
printf("Mesure locale en processus : %d requêtes par scénario, SQLite en mémoire.\n\n", $requests);
printf("%-62s %8s %8s %8s %8s %6s\n", 'Scénario', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms', 'erreurs');
foreach ($scenarios as $label => [$path, $authenticated]) {
    $r = $probe->measure($path, $requests, $authenticated);
    printf("%-62s %8.2f %8.2f %8.2f %8.2f %6d\n", $label, $r['p50_ms'], $r['p95_ms'], $r['p99_ms'], $r['max_ms'], $r['errors']);
    if ($r['p95_ms'] >= 300.0 || $r['errors'] > 0) {
        $failed = true;
    }
}
echo "\n" . ($failed ? "ÉCHEC : objectif p95 < 300 ms non tenu ou erreurs.\n" : "OK : p95 < 300 ms en processus. (Ce n'est pas un résultat k6.)\n");

exit($failed ? 1 : 0);
