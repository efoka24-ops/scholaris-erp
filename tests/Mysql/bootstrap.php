<?php

declare(strict_types=1);

/**
 * Amorçage de la suite MySQL (phpunit.mysql.xml). Ne s'exécute JAMAIS sans TEST_DB_DRIVER=mysql : aucune connexion MySQL
 * n'est ouverte par la suite normale (phpunit.xml), qui reste sur SQLite en mémoire.
 */
if (getenv('TEST_DB_DRIVER') !== 'mysql') {
    fwrite(STDERR, "Suite MySQL non exécutée : définissez TEST_DB_DRIVER=mysql (et TEST_DB_HOST, TEST_DB_PORT, TEST_DB_DATABASE, TEST_DB_USERNAME, TEST_DB_PASSWORD).\n");
    exit(2);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "Extension pdo_mysql absente.\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Priorité sur l'autoloader de Composer : la fabrique de connexions de test remplace celle de l'application.
spl_autoload_register(static function (string $class): void {
    if ($class === 'Scholaris\\Core\\Database\\ConnectionFactory') {
        require __DIR__ . '/Support/ConnectionFactory.php';
    }
}, true, true);
