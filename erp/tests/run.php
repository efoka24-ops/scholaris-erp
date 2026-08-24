<?php

declare(strict_types=1);

/**
 * Lanceur de tests.
 *
 * Usage : php tests/run.php
 *
 * Ecrit a la main plutot que d'ajouter PHPUnit : l'application ne depend
 * d'aucun paquet, et les tests doivent pouvoir tourner sur l'hebergement
 * lui-meme, ou Composer n'est pas garanti.
 */

use Scholaris\Autoloader;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

require $root.'/src/Autoloader.php';
Autoloader::register($root.'/src');
// Prefixe construit avec "\x5c" (antislash) pour eviter un antislash final,
// qui echapperait le guillemet fermant.
Autoloader::register($root.'/tests', 'Scholaris'."\x5c".'Tests'."\x5c");

// La session est simulee en memoire : PHP refuse session_start() sans sortie
// HTTP, et les tests manipulent directement le tableau $_SESSION.
$_SESSION = [];

$suites = [
    Scholaris\Tests\TenantIsolationTest::class,
    Scholaris\Tests\SecurityTest::class,
    Scholaris\Tests\StudentTest::class,
    Scholaris\Tests\SqlPortabilityTest::class,
    Scholaris\Tests\BusinessFlowTest::class,
    Scholaris\Tests\PublicFlowTest::class,
    Scholaris\Tests\PlatformAdminTest::class,
    Scholaris\Tests\RoutesSmokeTest::class,
    Scholaris\Tests\FeatureMatrixTest::class,
    Scholaris\Tests\DashboardTest::class,
    Scholaris\Tests\SchoolFactoryTest::class,
    Scholaris\Tests\OfflineSyncTest::class,
    Scholaris\Tests\AcademicYearTest::class,
];

$totalTests = 0;
$totalAssertions = 0;
$allFailures = [];

foreach ($suites as $suite) {
    /** @var Scholaris\Tests\TestCase $instance */
    $instance = new $suite();
    $result = $instance->run();

    $totalTests += $result['tests'];
    $totalAssertions += $result['assertions'];

    // "\x5c" est l'antislash, ecrit ainsi pour rester lisible et sans ambiguite.
    $separator = strrpos($result['name'], "\x5c");
    $shortName = $separator === false ? $result['name'] : substr($result['name'], $separator + 1);

    if ($result['failures'] === []) {
        echo "  OK   {$shortName} : {$result['tests']} test(s), {$result['assertions']} assertion(s)", PHP_EOL;
        continue;
    }

    echo "  ECHEC {$shortName} : ".count($result['failures'])." probleme(s)", PHP_EOL;

    foreach ($result['failures'] as $failure) {
        echo '         - '.$failure, PHP_EOL;
        $allFailures[] = $shortName.' : '.$failure;
    }
}

echo PHP_EOL;

if ($allFailures === []) {
    echo "Tous les tests passent : {$totalTests} test(s), {$totalAssertions} assertion(s).", PHP_EOL;
    exit(0);
}

echo count($allFailures)." echec(s) sur {$totalTests} test(s).", PHP_EOL;
exit(1);
