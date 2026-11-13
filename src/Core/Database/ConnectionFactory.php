<?php

declare(strict_types=1);

namespace Scholaris\Core\Database;

use PDO;
use Scholaris\Core\Config\Config;

/**
 * Ouvre la connexion PDO à la demande (jamais au démarrage) : un service sans base configurée
 * reste utilisable, et une base indisponible ne casse que les routes qui en ont besoin.
 */
final class ConnectionFactory
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return (string) $this->config->get('database.username', '') !== ''
            || $this->config->get('database.driver') === 'sqlite';
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $driver = (string) $this->config->get('database.driver', 'mysql');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlite') {
            $dsn = 'sqlite:' . (string) $this->config->get('database.database', ':memory:');
            $this->pdo = new PDO($dsn, null, null, $options);

            return $this->pdo;
        }

        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $driver,
            (string) $this->config->get('database.host'),
            (int) $this->config->get('database.port'),
            (string) $this->config->get('database.database'),
            (string) $this->config->get('database.charset', 'utf8mb4'),
        );
        $this->pdo = new PDO(
            $dsn,
            (string) $this->config->get('database.username'),
            (string) $this->config->get('database.password'),
            $options,
        );

        return $this->pdo;
    }
}
