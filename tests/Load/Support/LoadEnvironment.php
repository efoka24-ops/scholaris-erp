<?php

declare(strict_types=1);

namespace Tests\Load\Support;

use PDO;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Identity\RecordingSms;

/**
 * Environnement du test de charge local : configuration EXPLICITE (jamais le fichier .env, qui peut contenir des accès réels),
 * base SQLite fichier jetable, passerelles SMS et e-mail remplacées par des enregistreurs en mémoire, paiement simulé,
 * Dompdf réel. Partagé par le routeur du serveur PHP intégré, l'amorçage et les mesures en processus.
 */
final class LoadEnvironment
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function workDir(): string
    {
        $dir = self::root() . '/tests/Load/.work';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return $dir;
    }

    public static function config(string $database): Config
    {
        $work = self::workDir();

        return Config::fromArray([
            'app' => [
                'name' => 'SCHOLARIS', 'env' => 'loadtest', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1',
                'log_path' => 'tests/Load/.work/app.log', 'url' => 'http://127.0.0.1', 'two_factor_required' => false,
            ],
            'database' => ['driver' => 'sqlite', 'database' => $database],
            'api' => [
                'cors_origins' => [],
                'idempotent_paths' => ['#^/api/v1/payments$#', '#^/api/v1/cash/receipts$#', '#^/api/v1/documents$#'],
                'rate_limits' => [],
                'payment_webhook_secret' => 'secret-de-charge',
            ],
            // Paramètres Argon2id non précisés : valeurs par défaut du module (celles de la production).
            'identity' => ['secret_key' => 'cle-de-charge-identity'],
            'people' => ['secret_key' => 'cle-de-charge-people', 'storage_path' => $work . '/uploads'],
            'documents' => ['pdf_renderer' => 'dompdf', 'qr_generator' => 'library', 'pdf_cache_path' => 'tests/Load/.work/dompdf', 'pdf_paper' => 'A4', 'queue' => ['chunk' => 50, 'max_attempts' => 3, 'stale_after' => 600]],
            'backup' => ['path' => $work . '/backups', 'key' => '', 'keep' => 1],
        ]);
    }

    public static function kernel(string $database): Kernel
    {
        $kernel = Kernel::boot(self::root(), self::config($database), [
            SmsGateway::class => new RecordingSms(),
            MailGateway::class => new RecordingMail(),
        ]);
        self::tune($kernel->container()->get(ConnectionFactory::class)->pdo());

        return $kernel;
    }

    /**
     * Réglages de la connexion SQLite propres au banc de charge (plusieurs serveurs PHP écrivent dans le même fichier) :
     * journal WAL et attente de verrou de 5 s. Ils n'existent pas en production (MySQL).
     */
    public static function tune(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
    }
}
