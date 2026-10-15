<?php

declare(strict_types=1);

namespace Scholaris\Core\Database;

use PDO;
use RuntimeException;
use Scholaris\Core\Config\Config;

/**
 * REMPLACE Scholaris\Core\Database\ConnectionFactory UNIQUEMENT pour la suite MySQL (phpunit.mysql.xml, chargée par
 * tests/Mysql/bootstrap.php). Les tests existants construisent leur Kernel avec une base SQLite en mémoire : ici, toute
 * connexion de test est redirigée vers la base MySQL JETABLE désignée par les variables d'environnement :
 *
 *   TEST_DB_HOST (127.0.0.1), TEST_DB_PORT (3306), TEST_DB_DATABASE, TEST_DB_USERNAME, TEST_DB_PASSWORD
 *
 * Garde-fous : jamais sans TEST_DB_DRIVER=mysql (contrôlé par le bootstrap) ; le nom de la base doit contenir « test »
 * (sauf TEST_DB_ALLOW_ANY_NAME=yes) ; une base non vide qui n'a pas été créée par cette suite (table repère
 * `scholaris_test_marker`) est refusée, jamais effacée.
 *
 * Premier accès du processus : toutes les tables sont supprimées, les migrations sont rejouées sur la base vide, les lignes
 * de référence (rôles, modèles...) sont mémorisées. Accès suivants : les tables sont vidées et les lignes de référence
 * restaurées (rapide, isolation entre tests). Une configuration sans clé « app » (référence SQLite de db:check, tests
 * unitaires de portée) garde son comportement SQLite d'origine.
 */
final class ConnectionFactory
{
    private const MARKER = 'scholaris_test_marker';

    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $baseline = null;

    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->config->get('app.name') === null) {
            return $this->pdo = new PDO('sqlite:' . (string) $this->config->get('database.database', ':memory:'), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        $this->pdo = self::connect();
        if (self::$baseline === null) {
            self::dropEverything($this->pdo);
            $this->pdo->exec('CREATE TABLE ' . self::MARKER . ' (created_at VARCHAR(32) NOT NULL)');
            $this->pdo->exec('INSERT INTO ' . self::MARKER . " (created_at) VALUES ('" . date('c') . "')");
            (new Migrator($this, dirname(__DIR__, 3)))->migrate();
            self::$baseline = self::snapshot($this->pdo);
        } else {
            self::restore($this->pdo, self::$baseline);
        }

        return $this->pdo;
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    private static function connect(): PDO
    {
        $database = self::env('TEST_DB_DATABASE');
        if ($database === '' || self::env('TEST_DB_USERNAME') === '') {
            throw new RuntimeException('Variables TEST_DB_DATABASE et TEST_DB_USERNAME obligatoires pour la suite MySQL.');
        }
        if (stripos($database, 'test') === false && self::env('TEST_DB_ALLOW_ANY_NAME') !== 'yes') {
            throw new RuntimeException('Refus : le nom de la base de test doit contenir « test » (base « ' . $database . ' »).');
        }
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', self::env('TEST_DB_HOST', '127.0.0.1'), (int) self::env('TEST_DB_PORT', '3306'), $database),
            self::env('TEST_DB_USERNAME'),
            self::env('TEST_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );

        return $pdo;
    }

    /** @return list<string> */
    private static function tables(PDO $pdo): array
    {
        return array_map('strval', $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function dropEverything(PDO $pdo): void
    {
        $tables = self::tables($pdo);
        if ($tables !== [] && !in_array(self::MARKER, array_map('strtolower', $tables), true)) {
            throw new RuntimeException('Refus : la base de test contient des tables mais pas le repère « ' . self::MARKER . ' ». Utilisez une base vide et jetable.');
        }
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
        }
    }

    /** @return array<string, list<array<string, mixed>>> lignes présentes après les migrations (données de référence) */
    private static function snapshot(PDO $pdo): array
    {
        $baseline = [];
        foreach (self::tables($pdo) as $table) {
            if (strtolower($table) === self::MARKER) {
                continue;
            }
            $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
            if ($rows !== []) {
                $baseline[$table] = $rows;
            }
        }

        return $baseline;
    }

    /** @param array<string, list<array<string, mixed>>> $baseline */
    private static function restore(PDO $pdo, array $baseline): void
    {
        foreach (self::tables($pdo) as $table) {
            if (strtolower($table) === self::MARKER) {
                continue;
            }
            $quoted = '`' . $table . '`';
            if (!isset($baseline[$table]) && $pdo->query('SELECT 1 FROM ' . $quoted . ' LIMIT 1')->fetchColumn() === false) {
                continue;
            }
            $pdo->exec('DELETE FROM ' . $quoted);
            foreach ($baseline[$table] ?? [] as $row) {
                $columns = '`' . implode('`, `', array_keys($row)) . '`';
                $pdo->prepare('INSERT INTO ' . $quoted . ' (' . $columns . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            }
        }
    }
}
