<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\RateLimit\Clock;

/**
 * Sauvegarde logique chiffrée de la base (T143), en PHP pur : aucun outil externe (mysqldump absent du serveur).
 *
 * Format `scholaris-backup/1` : lignes JSON (une par événement : en-tête, table, lot de lignes, fin), compressées en gzip
 * quand zlib est présent, puis chiffrées et authentifiées par flux (libsodium secretstream XChaCha20-Poly1305, blocs de
 * 64 Kio). Une clé fausse, un bloc modifié ou un fichier tronqué sont détectés à la lecture. La structure des tables
 * n'est pas sauvegardée : elle vient des migrations (`php artisan migrate`) avant toute restauration.
 *
 * Lecture : transaction en lecture (instantané cohérent sous InnoDB), curseur non tamponné sous MySQL, lots de lignes :
 * la mémoire ne dépend pas de la taille des tables.
 */
final class BackupService
{
    public const FORMAT = 'scholaris-backup/1';
    public const MAGIC = "SCHBK1\n";
    public const FILE_PATTERN = '/^scholaris-\d{8}-\d{6}\.sbk$/';
    /** Tables volatiles ou propres à l'état de migration : jamais sauvegardées. */
    public const EXCLUDED = ['schema_migrations', 'rate_limits', 'idempotency_keys'];

    private const ROWS_PER_LINE = 500;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly Clock $clock,
        private readonly string $timezone = 'Africa/Douala',
    ) {
    }

    /** Clé de 32 octets à partir de sa forme base64 (configuration BACKUP_KEY). */
    public static function parseKey(string $encoded): string
    {
        $key = base64_decode(trim($encoded), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_KEY invalide : 32 octets encodés en base64 attendus (voir « php artisan backup:keygen »).');
        }

        return $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /** Nom de fichier horodaté (fuseau de la plateforme). */
    public function fileName(): string
    {
        return 'scholaris-' . (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone($this->timezone))->format('Ymd-His') . '.sbk';
    }

    /**
     * @return array{path: string, bytes: int, tables: array<string, int>, rows: int, compressed: bool}
     */
    public function backup(string $path, string $key): array
    {
        $pdo = $this->connections->pdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tables = array_values(array_diff($this->tables($pdo, $driver), self::EXCLUDED));

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Dossier de sauvegarde impossible à créer : ' . $directory);
        }
        $temporary = $path . '.partial';
        $out = new EncryptedSink($temporary, $key, extension_loaded('zlib'));

        $counts = [];
        $unbuffered = $driver === 'mysql';
        if ($unbuffered) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        $pdo->beginTransaction();
        try {
            $out->line(['type' => 'header', 'format' => self::FORMAT, 'created_at' => (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone($this->timezone))->format(DATE_ATOM), 'driver' => $driver, 'compressed' => $out->compressed(), 'tables' => $tables]);
            foreach ($tables as $table) {
                $counts[$table] = $this->dumpTable($pdo, $out, $table);
            }
            $out->line(['type' => 'end', 'tables' => $counts]);
            $out->close();
        } catch (\Throwable $e) {
            $out->abort();
            @unlink($temporary);
            throw $e;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($unbuffered) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Impossible de finaliser la sauvegarde : ' . $path);
        }
        @chmod($path, 0640);

        return ['path' => $path, 'bytes' => (int) filesize($path), 'tables' => $counts, 'rows' => array_sum($counts), 'compressed' => $out->compressed()];
    }

    /**
     * Restaure une sauvegarde dans une base DÉJÀ migrée. Refuse (sans rien écrire) si une table à remplir n'est pas vide,
     * sauf avec $force qui la vide d'abord. Tout se fait dans une seule transaction : en cas d'erreur, rien n'est modifié.
     *
     * @return array{tables: array<string, int>, rows: int}
     */
    public function restore(string $path, string $key, bool $force = false): array
    {
        $pdo = $this->connections->pdo();
        $source = new EncryptedSource($path, $key);
        $header = null;
        $current = null;
        $statement = null;
        $inserted = [];
        $expected = null;
        $columnsOf = [];

        $pdo->beginTransaction();
        try {
            foreach ($source->lines() as $event) {
                switch ($event['type'] ?? '') {
                    case 'header':
                        if (($event['format'] ?? '') !== self::FORMAT) {
                            throw new RuntimeException('Format de sauvegarde inconnu : ' . (string) ($event['format'] ?? '?'));
                        }
                        $header = $event;
                        $this->prepareTargets($pdo, array_values(array_map('strval', (array) ($event['tables'] ?? []))), $force);
                        break;
                    case 'table':
                        if ($header === null) {
                            throw new RuntimeException('Sauvegarde invalide : en-tête absent.');
                        }
                        $current = (string) $event['name'];
                        $columns = array_map('strval', (array) $event['columns']);
                        $known = $this->columnsOf($pdo, $current);
                        $missing = array_diff($columns, $known);
                        if ($missing !== []) {
                            throw new RuntimeException(sprintf('La table %s n\'a pas les colonnes %s : migrez la base avant de restaurer.', $current, implode(', ', $missing)));
                        }
                        $columnsOf[$current] = $columns;
                        $quotedColumns = implode(', ', array_map(self::quote(...), $columns));
                        $prepared = $pdo->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', self::quote($current), $quotedColumns, implode(', ', array_fill(0, count($columns), '?'))));
                        if ($prepared === false) {
                            throw new RuntimeException('Insertion impossible dans ' . $current);
                        }
                        $statement = $prepared;
                        $inserted[$current] = 0;
                        break;
                    case 'rows':
                        if ($statement === null || $current === null || ($event['table'] ?? '') !== $current) {
                            throw new RuntimeException('Sauvegarde invalide : lignes hors table.');
                        }
                        foreach ((array) $event['rows'] as $row) {
                            $statement->execute(array_map(self::decodeValue(...), array_values((array) $row)));
                            $inserted[$current]++;
                        }
                        break;
                    case 'end':
                        $expected = array_map('intval', (array) $event['tables']);
                        break;
                    default:
                        throw new RuntimeException('Événement de sauvegarde inconnu.');
                }
            }
            if ($header === null || $expected === null) {
                throw new RuntimeException('Sauvegarde incomplète (fin absente).');
            }
            ksort($expected);
            ksort($inserted);
            if ($expected !== $inserted) {
                throw new RuntimeException('Le nombre de lignes restaurées ne correspond pas à la sauvegarde.');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['tables' => $inserted, 'rows' => array_sum($inserted)];
    }

    /**
     * Supprime les sauvegardes les plus anciennes au-delà de $keep (rétention).
     *
     * @return list<string> fichiers supprimés
     */
    public function prune(string $directory, int $keep): array
    {
        if ($keep < 1) {
            throw new RuntimeException('La rétention doit conserver au moins une sauvegarde.');
        }
        $files = [];
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match(self::FILE_PATTERN, $name) === 1) {
                $files[] = $name;
            }
        }
        rsort($files);
        $deleted = [];
        foreach (array_slice($files, $keep) as $name) {
            if (@unlink($directory . '/' . $name)) {
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    private function dumpTable(PDO $pdo, EncryptedSink $out, string $table): int
    {
        $statement = $pdo->query('SELECT * FROM ' . self::quote($table));
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Lecture impossible : ' . $table);
        }
        $columns = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $columns[] = (string) ($statement->getColumnMeta($i)['name'] ?? '');
        }
        $out->line(['type' => 'table', 'name' => $table, 'columns' => $columns]);
        $batch = [];
        $count = 0;
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $batch[] = array_map(self::encodeValue(...), $row);
            $count++;
            if (count($batch) >= self::ROWS_PER_LINE) {
                $out->line(['type' => 'rows', 'table' => $table, 'rows' => $batch]);
                $batch = [];
            }
        }
        $statement->closeCursor();
        if ($batch !== []) {
            $out->line(['type' => 'rows', 'table' => $table, 'rows' => $batch]);
        }

        return $count;
    }

    /** @param list<string> $tables */
    private function prepareTargets(PDO $pdo, array $tables, bool $force): void
    {
        $existing = array_flip($this->tables($pdo, (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)));
        foreach ($tables as $table) {
            if (!isset($existing[$table])) {
                throw new RuntimeException('Table absente de la base cible : ' . $table . ' (lancez « php artisan migrate » avant de restaurer).');
            }
            $filled = $pdo->query('SELECT 1 FROM ' . self::quote($table) . ' LIMIT 1');
            $hasRows = $filled instanceof PDOStatement && $filled->fetchColumn() !== false;
            if ($hasRows && !$force) {
                throw new RuntimeException('La table ' . $table . ' n\'est pas vide : restauration refusée (option --force pour vider les tables avant de restaurer).');
            }
        }
        if ($force) {
            foreach ($tables as $table) {
                $pdo->exec('DELETE FROM ' . self::quote($table));
            }
        }
    }

    /** @return list<string> */
    private function tables(PDO $pdo, string $driver): array
    {
        $sql = $driver === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            : "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name";
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Liste des tables illisible.');
        }

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function columnsOf(PDO $pdo, string $table): array
    {
        $statement = $pdo->query('SELECT * FROM ' . self::quote($table) . ' LIMIT 0');
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Table illisible : ' . $table);
        }
        $columns = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $columns[] = (string) ($statement->getColumnMeta($i)['name'] ?? '');
        }
        $statement->closeCursor();

        return $columns;
    }

    private static function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new RuntimeException('Identifiant SQL invalide : ' . $identifier);
        }

        return '`' . $identifier . '`';
    }

    private static function encodeValue(mixed $value): mixed
    {
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return ['$b' => base64_encode($value)];
        }

        return $value;
    }

    private static function decodeValue(mixed $value): mixed
    {
        return is_array($value) && isset($value['$b']) ? base64_decode((string) $value['$b'], true) : $value;
    }
}
