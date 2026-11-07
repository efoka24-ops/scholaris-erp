<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Console;

use PDO;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Support\Ulid;
use Throwable;

/**
 * `db:check` : contrôle de la base configurée (MySQL 8 en production) sans rien y laisser.
 *
 *  1. migrations toutes appliquées ;
 *  2. tables et colonnes attendues présentes : la référence est le résultat des migrations rejouées sur une base SQLite en
 *     mémoire (les migrations sont la source de vérité du schéma) ;
 *  3. jeu de caractères utf8mb4 (base, connexion, tables) ;
 *  4. contrôle fonctionnel : écriture, lecture, modification et suppression d'une ligne de test sur quelques tables clés,
 *     dans une transaction ANNULÉE (y compris un caractère à quatre octets pour prouver utf8mb4) ; aucune donnée n'est conservée.
 *
 * Code de sortie : 0 si tout est conforme, 1 s'il y a au moins un écart.
 */
final class DbCheckCommand
{
    /** @var list<array{0: string, 1: string, 2: string}> [état, libellé, détail] ; état : OK, ÉCART ou INFO */
    private array $report = [];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly Migrator $migrator,
        private readonly Config $config,
        private readonly string $basePath,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args = []): int
    {
        $this->report = [];
        if (!$this->connections->isConfigured()) {
            fwrite(STDERR, "Base non configurée (DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD).\n");

            return 1;
        }

        try {
            $pdo = $this->connections->pdo();
        } catch (Throwable $e) {
            fwrite(STDERR, 'Connexion impossible : ' . $e->getMessage() . "\n");

            return 1;
        }
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->add('INFO', 'Moteur', $driver . ' ' . (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));

        $this->checkMigrations();
        $this->checkSchema($pdo, $driver);
        $this->checkCharset($pdo, $driver);
        $this->checkFunctional($pdo, $driver);

        $gaps = count(array_filter($this->report, static fn (array $line): bool => $line[0] === 'ÉCART'));
        echo "Contrôle de la base (db:check)\n";
        foreach ($this->report as [$state, $label, $detail]) {
            echo sprintf("  [%s] %s%s\n", $state, $label, $detail === '' ? '' : ' : ' . $detail);
        }
        echo $gaps === 0 ? "Résultat : OK, aucun écart.\n" : "Résultat : {$gaps} ÉCART(S).\n";

        return $gaps === 0 ? 0 : 1;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public function report(): array
    {
        return $this->report;
    }

    private function add(string $state, string $label, string $detail = ''): void
    {
        $this->report[] = [$state, $label, $detail];
    }

    private function checkMigrations(): void
    {
        try {
            $status = $this->migrator->status();
        } catch (Throwable $e) {
            $this->add('ÉCART', 'Migrations', 'illisibles : ' . $e->getMessage());

            return;
        }
        $pending = array_keys(array_filter($status, static fn (bool $applied): bool => !$applied));
        $this->add(
            $pending === [] ? 'OK' : 'ÉCART',
            'Migrations appliquées',
            $pending === [] ? count($status) . ' sur ' . count($status) : count($pending) . ' en attente (' . implode(', ', array_slice($pending, 0, 5)) . (count($pending) > 5 ? ', ...' : '') . ') : lancer « php artisan migrate »',
        );
    }

    private function checkSchema(PDO $pdo, string $driver): void
    {
        try {
            $expected = $this->referenceSchema();
            $actual = self::schemaOf($pdo, $driver);
        } catch (Throwable $e) {
            $this->add('ÉCART', 'Tables et colonnes', 'contrôle impossible : ' . $e->getMessage());

            return;
        }
        $missingTables = [];
        $missingColumns = [];
        $extraColumns = [];
        foreach ($expected as $table => $columns) {
            if (!isset($actual[$table])) {
                $missingTables[] = $table;
                continue;
            }
            foreach (array_diff($columns, $actual[$table]) as $column) {
                $missingColumns[] = $table . '.' . $column;
            }
            foreach (array_diff($actual[$table], $columns) as $column) {
                $extraColumns[] = $table . '.' . $column;
            }
        }
        $columnCount = array_sum(array_map('count', $expected));
        $this->add(
            $missingTables === [] && $missingColumns === [] ? 'OK' : 'ÉCART',
            'Tables et colonnes attendues',
            $missingTables === [] && $missingColumns === []
                ? count($expected) . ' tables, ' . $columnCount . ' colonnes présentes'
                : ($missingTables === [] ? '' : 'tables absentes : ' . implode(', ', $missingTables) . '. ') . ($missingColumns === [] ? '' : 'colonnes absentes : ' . implode(', ', $missingColumns)),
        );
        if ($extraColumns !== []) {
            $this->add('INFO', 'Colonnes en plus (non bloquant)', implode(', ', array_slice($extraColumns, 0, 10)));
        }
        $unknown = array_diff(array_keys($actual), array_keys($expected));
        if ($unknown !== []) {
            $this->add('INFO', 'Tables hors migrations (non bloquant)', implode(', ', array_slice(array_values($unknown), 0, 10)));
        }
    }

    private function checkCharset(PDO $pdo, string $driver): void
    {
        if ($driver !== 'mysql') {
            $this->add('INFO', 'Jeu de caractères', 'sans objet pour ' . $driver . ' (contrôle réservé à MySQL)');

            return;
        }
        $row = self::query($pdo, 'SELECT @@character_set_database AS db, @@character_set_connection AS conn, @@collation_database AS coll')->fetch() ?: [];
        $this->add(
            str_starts_with((string) ($row['db'] ?? ''), 'utf8mb4') ? 'OK' : 'ÉCART',
            'Jeu de caractères de la base',
            (string) ($row['db'] ?? '?') . ' / ' . (string) ($row['coll'] ?? '?'),
        );
        $this->add(
            str_starts_with((string) ($row['conn'] ?? ''), 'utf8mb4') ? 'OK' : 'ÉCART',
            'Jeu de caractères de la connexion',
            (string) ($row['conn'] ?? '?'),
        );
        $wrong = self::query($pdo, "SELECT table_name AS t, table_collation AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND (table_collation IS NULL OR table_collation NOT LIKE 'utf8mb4%')")->fetchAll();
        $this->add(
            $wrong === [] ? 'OK' : 'ÉCART',
            'Tables en utf8mb4',
            $wrong === [] ? 'toutes' : implode(', ', array_map(static fn (array $r): string => $r['t'] . ' (' . ($r['c'] ?? '?') . ')', array_slice($wrong, 0, 10))),
        );
        $engines = self::query($pdo, "SELECT table_name AS t, engine AS e FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND (engine IS NULL OR engine <> 'InnoDB')")->fetchAll();
        $this->add(
            $engines === [] ? 'OK' : 'ÉCART',
            'Moteur transactionnel InnoDB',
            $engines === [] ? 'toutes les tables' : implode(', ', array_map(static fn (array $r): string => $r['t'] . ' (' . ($r['e'] ?? '?') . ')', array_slice($engines, 0, 10))),
        );
    }

    /**
     * Contrôle fonctionnel dans une transaction annulée. Les lignes de test portent un identifiant « dbcheck » ; l'état
     * final est vérifié (aucune ligne ne subsiste).
     */
    private function checkFunctional(PDO $pdo, string $driver): void
    {
        $tenant = 'dbcheck-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $person = (string) new Ulid();
        $text = "Éléonore N'Dour 😀"; // accents, apostrophe et caractère à quatre octets
        $now = '2026-01-01T00:00:00+01:00';
        // table, colonne de clé, valeur de clé, INSERT, paramètres, colonne relue (le SQL de relecture est généré)
        $tests = [
            ['tenants', 'id', $tenant, 'INSERT INTO tenants (id, name, status, plan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$tenant, $text, 'active', 'test', $now, $now], 'name'],
            ['people', 'id', $person, 'INSERT INTO people (id, tenant_id, first_name, last_name, created_at) VALUES (?, ?, ?, ?, ?)', [$person, $tenant, $text, 'Test', $now], 'first_name'],
            ['number_sequences', 'tenant_id', $tenant, 'INSERT INTO number_sequences (tenant_id, establishment_id, kind, year, current_value) VALUES (?, ?, ?, ?, ?)', [$tenant, 'dbcheck', 'receipt', 2026, 1], 'current_value'],
            ['idempotency_keys', 'tenant_id', $tenant, 'INSERT INTO idempotency_keys (tenant_id, idem_key, route, request_hash, status_code, response_body, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$tenant, 'k-dbcheck', '/dbcheck', str_repeat('a', 64), 200, $text, $now], 'response_body'],
        ];

        if ($pdo->inTransaction()) {
            $this->add('ÉCART', 'Contrôle fonctionnel', 'une transaction est déjà ouverte : contrôle non exécuté');

            return;
        }
        $failures = [];
        $pdo->beginTransaction();
        try {
            foreach ($tests as [$table, $keyColumn, $key, $insert, $params, $column]) {
                $select = "SELECT {$column} FROM {$table} WHERE {$keyColumn} = ?";
                $update = "UPDATE {$table} SET {$column} = ? WHERE {$keyColumn} = ?";
                $delete = "DELETE FROM {$table} WHERE {$keyColumn} = ?";
                $count = "SELECT COUNT(*) FROM {$table} WHERE {$keyColumn} = ?";
                $numeric = $column === 'current_value';
                try {
                    $pdo->prepare($insert)->execute($params);
                    $value = $pdo->prepare($select);
                    $value->execute([$key]);
                    $stored = $value->fetchColumn();
                    $expected = $numeric ? 1 : $text;
                    if ((string) $stored !== (string) $expected) {
                        $failures[] = $table . ' : relecture différente (' . var_export($stored, true) . ')';
                    }
                    $changed = $numeric ? 2 : 'Modifié é 😀';
                    $pdo->prepare($update)->execute([$changed, $key]);
                    $value->execute([$key]);
                    if ((string) $value->fetchColumn() !== (string) $changed) {
                        $failures[] = $table . ' : modification non relue';
                    }
                    $pdo->prepare($delete)->execute([$key]);
                    $remaining = $pdo->prepare($count);
                    $remaining->execute([$key]);
                    if ((int) $remaining->fetchColumn() !== 0) {
                        $failures[] = $table . ' : suppression sans effet';
                    }
                } catch (Throwable $e) {
                    $failures[] = $table . ' : ' . $e->getMessage();
                }
            }
        } finally {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
                // aucune transaction à annuler (déjà terminée par le serveur)
            }
        }

        // Preuve qu'aucune donnée de test ne subsiste.
        $left = 0;
        foreach ([['tenants', 'id', $tenant], ['people', 'id', $person], ['number_sequences', 'tenant_id', $tenant], ['idempotency_keys', 'tenant_id', $tenant]] as [$table, $column, $value]) {
            try {
                $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
                $statement->execute([$value]);
                $left += (int) $statement->fetchColumn();
            } catch (Throwable) {
                // table absente : déjà signalée par le contrôle du schéma
            }
        }
        if ($left > 0) {
            $failures[] = $left . ' ligne(s) de test subsistent après annulation (tables non transactionnelles ?)';
        }

        $this->add(
            $failures === [] ? 'OK' : 'ÉCART',
            'Écriture, lecture, modification, suppression (transaction annulée)',
            $failures === [] ? 'tenants, people, number_sequences, idempotency_keys ; accents et caractère 4 octets relus à l\'identique ; aucune donnée conservée' : implode(' | ', $failures),
        );
        if ($driver === 'mysql' && $this->config->get('app.env') === 'production') {
            $this->add('INFO', 'Environnement', 'production');
        }
    }

    /** @return array<string, list<string>> table => colonnes, d'après les migrations rejouées sur SQLite en mémoire */
    private function referenceSchema(): array
    {
        $reference = new ConnectionFactory(Config::fromArray(['database' => ['driver' => 'sqlite', 'database' => ':memory:']]));
        (new Migrator($reference, $this->basePath))->migrate();
        $schema = self::schemaOf($reference->pdo(), 'sqlite');
        unset($schema['schema_migrations']);

        return $schema;
    }

    private static function query(PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Requête impossible : ' . $sql);
        }

        return $statement;
    }

    /** @return array<string, list<string>> */
    private static function schemaOf(PDO $pdo, string $driver): array
    {
        $schema = [];
        if ($driver === 'sqlite') {
            foreach (self::query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $schema[(string) $table] = array_map('strval', array_column(self::query($pdo, 'PRAGMA table_info(' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $table) . ')')->fetchAll(), 'name'));
            }
        } else {
            foreach (self::query($pdo, 'SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position')->fetchAll() as $row) {
                $schema[strtolower((string) $row['t'])][] = (string) $row['c'];
            }
        }
        ksort($schema);

        return $schema;
    }
}
