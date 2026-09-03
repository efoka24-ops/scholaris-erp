<?php

declare(strict_types=1);

namespace Scholaris\Database;

use RuntimeException;

/**
 * Application des migrations SQL.
 *
 * Les fichiers de database/migrations sont ecrits en SQL portable, sans option
 * propre a un moteur. Le migrateur ajoute lui-meme le suffixe InnoDB/utf8mb4
 * sur MySQL : c'est la seule difference entre la base de production et celle
 * des tests, ce qui evite de maintenir deux schemas.
 */
final class Migrator
{
    private Connection $db;

    private string $directory;

    public function __construct(Connection $db, string $directory)
    {
        $this->db = $db;
        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * Applique les migrations non encore jouees.
     *
     * @return list<string> noms des fichiers appliques
     */
    public function migrate(): array
    {
        $files = $this->files();

        if ($files === []) {
            throw new RuntimeException("Aucune migration trouvee dans {$this->directory}");
        }

        // La table de suivi est creee par la premiere migration : avant elle,
        // la liste des migrations deja jouees est forcement vide.
        $applied = $this->tableExists('migrations') ? $this->appliedFilenames() : [];
        $ran = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, $applied, true)) {
                continue;
            }

            foreach ($this->statements((string) file_get_contents($file)) as $statement) {
                $this->db->pdo()->exec($statement);
            }

            $this->db->execute(
                'INSERT INTO migrations (id, filename, applied_at) VALUES (:id, :filename, :now)',
                ['id' => Table::uuid(), 'filename' => $name, 'now' => date('Y-m-d H:i:s')]
            );

            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->directory.'/*.sql');

        if ($files === false) {
            return [];
        }

        sort($files);

        return array_values($files);
    }

    /**
     * @return list<string>
     */
    private function appliedFilenames(): array
    {
        $rows = $this->db->select('SELECT filename FROM migrations');

        return array_map(static fn (array $row): string => (string) $row['filename'], $rows);
    }

    public function tableExists(string $table): bool
    {
        try {
            $this->db->scalar(sprintf('SELECT 1 FROM %s LIMIT 1', $table));

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Decoupe un fichier en instructions et applique les options MySQL.
     *
     * Une instruction peut etre reservee a un moteur en la precedant de
     * "-- @mysql" ou "-- @sqlite". Cela sert aux rares cas ou les deux moteurs
     * divergent vraiment : SQLite ne sait pas modifier une colonne existante,
     * la ou MySQL le fait par ALTER TABLE.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $driver = $this->db->driver();
        $isMysql = $driver === 'mysql';

        // Les instructions marquees pour un autre moteur sont retirees avant
        // tout decoupage, avec le contenu qui les suit jusqu'au point-virgule.
        $sql = preg_replace_callback(
            '/^\s*--\s*@(mysql|sqlite)\s*$(.*?);/ms',
            static fn (array $m): string => strtolower($m[1]) === $driver ? $m[2].';' : '',
            $sql
        ) ?? $sql;

        // Retire les commentaires en ligne avant le decoupage : ils peuvent
        // contenir des points-virgules.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = [];

        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);

            if ($statement === '') {
                continue;
            }

            if ($isMysql && stripos($statement, 'CREATE TABLE') === 0) {
                $statement .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            }

            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * Supprime toutes les tables du schema. Reserve aux tests et a la
     * reinitialisation d'un environnement de developpement.
     */
    public function dropAll(): void
    {
        $driver = $this->db->driver();

        if ($driver === 'mysql') {
            $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            $tables = $this->db->select('SHOW TABLES');

            foreach ($tables as $row) {
                $name = (string) reset($row);
                $this->db->pdo()->exec("DROP TABLE IF EXISTS `{$name}`");
            }

            $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');

            return;
        }

        $this->db->pdo()->exec('PRAGMA foreign_keys = OFF');
        $tables = $this->db->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        );

        foreach ($tables as $row) {
            $this->db->pdo()->exec(sprintf('DROP TABLE IF EXISTS "%s"', $row['name']));
        }

        $this->db->pdo()->exec('PRAGMA foreign_keys = ON');
    }
}
