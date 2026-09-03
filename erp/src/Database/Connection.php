<?php

declare(strict_types=1);

namespace Scholaris\Database;

use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * Acces a la base, en requetes preparees exclusivement.
 *
 * Aucune methode n'accepte de valeur concatenee dans le SQL : toute donnee
 * passe par un parametre lie. C'est la seule barriere contre l'injection SQL,
 * et elle doit rester sans exception.
 */
final class Connection
{
    private PDO $pdo;

    /** @var list<array{sql: string, params: array<string, mixed>, ms: float}> */
    private array $log = [];

    private bool $logQueries;

    public function __construct(PDO $pdo, bool $logQueries = false)
    {
        $this->pdo = $pdo;
        $this->logQueries = $logQueries;
    }

    public static function mysql(
        string $host,
        string $database,
        string $username,
        string $password,
        int $port = 3306
    ): self {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        return new self(new PDO($dsn, $username, $password, self::options()));
    }

    public static function sqlite(string $path): self
    {
        $pdo = new PDO('sqlite:'.$path, null, null, self::options());
        // Sans cela SQLite ignore les cles etrangeres, et les tests passeraient
        // la ou MySQL refuserait l'ecriture.
        $pdo->exec('PRAGMA foreign_keys = ON');

        return new self($pdo);
    }

    /**
     * @return array<int, mixed>
     */
    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Requetes reellement preparees cote serveur : l'emulation
            // reconstruit le SQL en PHP et affaiblit la protection.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $startedAt = microtime(true);

        $this->assertPlaceholdersMatch($sql, $params);

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        if ($this->logQueries) {
            $this->log[] = [
                'sql' => $sql,
                'params' => $params,
                'ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ];
        }

        return $statement;
    }

    /**
     * Verifie que les placeholders du SQL correspondent aux parametres fournis.
     *
     * MySQL en preparation native refuse qu'un placeholder nomme apparaisse
     * deux fois ("SQLSTATE[HY093] Invalid parameter number"), la ou SQLite
     * l'accepte. Sans ce controle, un tel defaut passerait les tests et ne se
     * revelerait qu'en production, avec un message peu parlant. Il signale
     * aussi les parametres manquants ou en trop.
     *
     * @param  array<string, mixed>  $params
     */
    private function assertPlaceholdersMatch(string $sql, array $params): void
    {
        // Retire les chaines litterales : un ":" y serait un faux positif.
        $stripped = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $sql) ?? $sql;

        preg_match_all('/(?<![:\w]):([a-z_][a-z0-9_]*)/i', $stripped, $matches);

        $used = $matches[1];
        $duplicates = array_keys(array_filter(array_count_values($used), static fn (int $n): bool => $n > 1));

        if ($duplicates !== []) {
            throw new InvalidArgumentException(sprintf(
                'Placeholder repete dans une requete (refuse par MySQL) : :%s',
                implode(', :', $duplicates)
            ));
        }

        $missing = array_diff(array_unique($used), array_keys($params));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Parametre absent pour le placeholder : :'.implode(', :', $missing)
            );
        }

        $extra = array_diff(array_keys($params), $used);

        if ($extra !== []) {
            throw new InvalidArgumentException(
                'Parametre fourni sans placeholder correspondant : '.implode(', ', $extra)
            );
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Execute le callback dans une transaction, avec annulation sur exception.
     * Les transactions imbriquees reutilisent celle en cours plutot que
     * d'echouer : PDO ne gere pas les points de sauvegarde nommes de facon
     * portable entre MySQL et SQLite.
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback($this);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * @return list<array{sql: string, params: array<string, mixed>, ms: float}>
     */
    public function queryLog(): array
    {
        return $this->log;
    }
}
