<?php

declare(strict_types=1);

/**
 * Mesure en processus (base de charge amorcée par run.php) : lot de N documents (création + traitement en file par tranches
 * de 50, comme documents:process-jobs), puis rendu PDF Dompdf réel de chacun (aperçu). Imprime un JSON : durées, mémoire de pointe.
 *
 *   php -d memory_limit=1G tests/Load/documents-batch.php 200
 */

use Nyholm\Psr7\ServerRequest;
use Tests\Load\Support\LoadEnvironment;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$count = max(1, (int) ($argv[1] ?? 200));
$work = LoadEnvironment::workDir();
$fixture = json_decode((string) file_get_contents($work . '/fixture.json'), true);
$kernel = LoadEnvironment::kernel($work . '/charge.sqlite');
$headers = ['Authorization' => 'Bearer ' . $fixture['tokens']['admin'], 'X-Establishment-Id' => $fixture['establishment'], 'Content-Type' => 'application/json'];
$call = static function (string $method, string $path, ?array $body = null) use ($kernel, $headers): array {
    $response = $kernel->handle(new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));

    return [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Content-Type')];
};

$people = array_slice($fixture['learner_sample'], 0, $count);
$items = array_map(static fn (string $p): array => ['subject_person_id' => $p, 'data' => ['academic_year' => '2026-2027', 'class_name' => '3e A']], $people);

$memoryBefore = memory_get_usage(true);
$t0 = microtime(true);
[$status, $raw] = $call('POST', '/document-batches', ['type' => 'attestation_simple', 'items' => $items]);
if ($status !== 202 && $status !== 201) {
    fwrite(STDERR, $raw);
    exit(1);
}
$batch = json_decode($raw, true)['data'];
$calls = 0;
do {
    [, $raw] = $call('POST', '/document-batches/' . $batch['id'] . '/process');
    $batch = json_decode($raw, true)['data'];
    $calls++;
} while ($batch['status'] !== 'done' && $calls < 100);
$issueSeconds = microtime(true) - $t0;

// Rendu PDF réel de chaque document du lot (aperçu).
$ids = [];
$cursor = null;
do {
    [, $raw] = $call('GET', '/documents?per_page=200' . ($cursor ? '&cursor=' . $cursor : ''));
    $page = json_decode($raw, true);
    foreach ($page['data'] as $doc) {
        $ids[] = $doc['id'];
    }
    $cursor = $page['meta']['next_cursor'] ?? null;
} while (is_string($cursor) && count($ids) < $count);
$ids = array_slice($ids, 0, $count);
$t1 = microtime(true);
$bytes = 0;
$pdfErrors = 0;
foreach ($ids as $id) {
    [$status, $pdf, $type] = $call('GET', '/documents/' . $id . '/preview');
    if ($status !== 200 || !str_starts_with($pdf, '%PDF')) {
        $pdfErrors++;
    }
    $bytes += strlen($pdf);
}
$renderSeconds = microtime(true) - $t1;

echo json_encode([
    'documents' => $count,
    'batch_status' => $batch['status'], 'processed' => $batch['processed'], 'failed' => $batch['failed'], 'process_calls' => $calls,
    'issue_seconds' => round($issueSeconds, 2), 'issue_ms_per_document' => round(1000 * $issueSeconds / $count, 1),
    'pdf_rendered' => count($ids), 'pdf_errors' => $pdfErrors, 'pdf_seconds' => round($renderSeconds, 2), 'pdf_ms_per_document' => round(1000 * $renderSeconds / max(1, count($ids)), 1),
    'pdf_total_kib' => (int) round($bytes / 1024), 'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 1), 'memory_before_mib' => round($memoryBefore / 1048576, 1),
    'projection_1000_pdf_minutes' => round(1000 * ($issueSeconds / $count + $renderSeconds / max(1, count($ids))) / 60, 1),
], JSON_UNESCAPED_UNICODE);
