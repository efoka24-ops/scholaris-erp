<?php

declare(strict_types=1);

namespace Scholaris\Core\Database;

use PDO;
use RuntimeException;

/**
 * Exécute les migrations SQL : database/migrations/*.sql puis celles de chaque module
 * (src/Modules/<Groupe>/<Module>/Database/Migrations/*.sql), dans l'ordre des noms de fichiers
 * (préfixe AAAAMMJJHHMM_). L'état est tenu dans la table schema_migrations.
 */
final class Migrator
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly string $basePath,
    ) {
    }

    /** @return list<string> noms des migrations appliquées pendant cet appel */
    public function migrate(): array
    {
        $pdo = $this->prepare();
        $applied = $this->appliedNames($pdo);
        $done = [];

        foreach ($this->files() as $name => $file) {
            if (isset($applied[$name])) {
                continue;
            }
            foreach ($this->statements((string) file_get_contents($file)) as $sql) {
                $pdo->exec($sql);
            }
            $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)')
                ->execute([$name, date('Y-m-d H:i:s')]);
            $done[] = $name;
        }

        return $done;
    }

    /** @return array<string, bool> nom => appliquée */
    public function status(): array
    {
        $pdo = $this->prepare();
        $applied = $this->appliedNames($pdo);
        $status = [];
        foreach (array_keys($this->files()) as $name) {
            $status[$name] = isset($applied[$name]);
        }

        return $status;
    }

    private function prepare(): PDO
    {
        $pdo = $this->connections->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');

        return $pdo;
    }

    /** @return array<string, true> */
    private function appliedNames(PDO $pdo): array
    {
        $names = [];
        $statement = $pdo->query('SELECT name FROM schema_migrations');
        if ($statement === false) {
            throw new RuntimeException('Lecture de schema_migrations impossible.');
        }
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $names[(string) $name] = true;
        }

        return $names;
    }

    /** @return array<string, string> nom => chemin, triés par nom */
    private function files(): array
    {
        $paths = array_merge(
            glob($this->basePath . '/database/migrations/*.sql') ?: [],
            glob($this->basePath . '/src/Modules/*/*/Database/Migrations/*.sql') ?: [],
            // Sous-modules d'une application (src/Modules/Apps/Higher/<Module>/Database/Migrations).
            glob($this->basePath . '/src/Modules/Apps/*/*/Database/Migrations/*.sql') ?: [],
        );

        $files = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (isset($files[$name])) {
                throw new RuntimeException('Nom de migration en double : ' . $name);
            }
            $files[$name] = $path;
        }
        ksort($files);

        return $files;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(
            array_map('trim', explode(';', $sql)),
            static fn (string $statement): bool => $statement !== '',
        ));
    }
}
