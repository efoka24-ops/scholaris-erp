<?php

declare(strict_types=1);

/**
 * Mesure NF-S2 en production (C-118) : PDF de bulletins pré-rendus par la VRAIE file (`DocumentJobService::drain`, comme
 * `documents:process-jobs --workers=W`) dans W processus PHP indépendants qui se partagent une base SQLite fichier (WAL).
 * Les bulletins sont les contenus HTML réels du passage C-117 (tests/Load/.work/parallel-render/bulletin-*.html), insérés
 * comme documents émis (catégorie bulletin). Avant : rendu à la demande en profil standard, un processus (échantillon).
 *
 *   php tests/Load/Secondary/prerender-queue.php [bulletins=240] [processus=4]
 *   (interne) php tests/Load/Secondary/prerender-queue.php --worker <base.sqlite> <dossier-pdf>
 *
 * Affiche un JSON. SQLite local et poste Windows : ordre de grandeur, à confirmer sur le serveur (MySQL).
 */

use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Documents\Application\DocumentJobService;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Tests\Load\Support\LoadEnvironment;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$kernelFor = static function (string $db, string $store): Kernel {
    $base = LoadEnvironment::config($db);
    $config = [];
    foreach (['app', 'database', 'api', 'identity', 'people', 'documents', 'backup'] as $key) {
        $config[$key] = $base->get($key);
    }
    $config['documents']['pdf_store_path'] = $store;
    $config['documents']['pdf_cache_path'] = $store . '/dompdf-' . getmypid();
    $kernel = Kernel::boot(LoadEnvironment::root(), Config::fromArray($config));
    LoadEnvironment::tune($kernel->container()->get(ConnectionFactory::class)->pdo());

    return $kernel;
};

if (($argv[1] ?? '') === '--worker') {
    $kernel = $kernelFor((string) $argv[2], (string) $argv[3]);
    $t0 = microtime(true);
    $stats = $kernel->container()->get(DocumentJobService::class)->drain(10, null, 1800);
    echo json_encode(['rendered' => $stats['rendered'], 'failed' => $stats['render_failed'], 'seconds' => round(microtime(true) - $t0, 2)]), "\n";
    exit(0);
}

$count = max(1, (int) ($argv[1] ?? 240));
$workers = max(1, (int) ($argv[2] ?? 4));
$work = LoadEnvironment::workDir();
$sources = glob($work . '/parallel-render/bulletin-*.html') ?: [];
if ($sources === []) {
    fwrite(STDERR, "Aucun bulletin HTML : lancer d'abord SecondaryLoadTest (C-117).\n");
    exit(1);
}
$db = $work . '/prerender.sqlite';
$store = $work . '/prerender-pdf';
foreach ([$db, $db . '-wal', $db . '-shm'] as $file) {
    @unlink($file);
}
if (is_dir($store)) {
    exec((PHP_OS_FAMILY === 'Windows' ? 'rmdir /S /Q ' : 'rm -rf ') . escapeshellarg($store));
}
$kernel = $kernelFor($db, $store);
$kernel->container()->get(Migrator::class)->migrate();
$pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();
$insert = $pdo->prepare(
    'INSERT INTO documents (id, tenant_id, establishment_id, type_code, category, number, verification_token, fingerprint, status, template_id, template_version, '
    . 'locale, subject_person_id, holder_name, holder_mask, data_json, content, issued_by, issued_via, issued_at) '
    . "VALUES (?, 't-charge', 'e-charge', 'secondary_report_card', 'bulletin', ?, ?, ?, 'valid', 'tpl', 1, 'fr', ?, 'Élève Fictif', 'É. F.', '{}', ?, 'charge', 'api', ?)",
);
$pdo->beginTransaction();
for ($i = 0; $i < $count; $i++) {
    $html = (string) file_get_contents($sources[$i % count($sources)]);
    $id = (string) new Scholaris\Core\Support\Ulid();
    $insert->execute([$id, 'LEG-B-' . $i, substr(hash('sha256', $id), 0, 32), hash('sha256', $html), 'p' . $i, $html, date(DATE_ATOM)]);
}
$pdo->commit();

// Avant : rendu à la demande, profil standard, un processus (échantillon de 10 après un rendu de chauffe).
$service = $kernel->container()->get(DocumentService::class);
$rows = $pdo->query('SELECT * FROM documents ORDER BY id LIMIT 11')->fetchAll(PDO::FETCH_ASSOC);
$service->pdfOfRow($rows[0], 'standard');
$t0 = microtime(true);
foreach (array_slice($rows, 1) as $row) {
    $service->pdfOfRow($row, 'standard');
}
$standardMs = 1000 * (microtime(true) - $t0) / (count($rows) - 1);
unset($kernel, $service, $pdo);

// Après : W processus de la file en parallèle.
$t0 = microtime(true);
$procs = [];
for ($w = 0; $w < $workers; $w++) {
    $procs[] = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--worker', $db, $store], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
}
$results = [];
foreach ($procs as $w => $proc) {
    $out = (string) stream_get_contents($pipes[$w][1]);
    $err = (string) stream_get_contents($pipes[$w][2]);
    proc_close($proc);
    $results[] = json_decode(trim($out), true) ?? ['erreur' => mb_substr($out . $err, 0, 300)];
}
$wall = microtime(true) - $t0;
$rendered = array_sum(array_map(static fn (array $r): int => (int) ($r['rendered'] ?? 0), $results));
$check = new PDO('sqlite:' . $db);
$distinct = (int) $check->query("SELECT COUNT(*) FROM documents WHERE pdf_rendered_at IS NOT NULL AND pdf_profile = 'light'")->fetchColumn();
$perSecond = $rendered / max(0.001, $wall);

echo json_encode([
    'bulletins' => $count, 'processus' => $workers,
    'avant_standard_ms_par_bulletin_un_processus' => round($standardMs, 1),
    'avant_projection_3000_min' => round(3000 * $standardMs / 1000 / 60, 1),
    'apres_rendus' => $rendered, 'apres_documents_marques' => $distinct, 'apres_duree_s' => round($wall, 2),
    'apres_bulletins_par_s' => round($perSecond, 2), 'apres_projection_3000_min' => round(3000 / $perSecond / 60, 1),
    'processus_detail' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
