<?php

declare(strict_types=1);

/**
 * Pile réelle des tests Playwright (C-110) : Kernel, modules, migrations, SQLite FICHIER propre au fichier de test
 * (dossier E2E_DIR), aucune donnée réelle. Seules les passerelles SMS et e-mail sont remplacées par un enregistreur
 * (E2E_DIR/outbox.jsonl) pour que le test lise les codes d'activation comme le ferait la personne sur son téléphone.
 * Rien n'est lu dans le .env du dépôt.
 */

use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$dir = (string) getenv('E2E_DIR');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "E2E_DIR absent : dossier de la pile de test introuvable.\n");
    exit(2);
}

$_ENV['APP_KEY'] = (string) getenv('E2E_APP_KEY');
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dir . '/scholaris.sqlite';
$_ENV['SMS_GATEWAY'] = 'log';
$_ENV['MAIL_MAILER'] = 'log';
$_ENV['PAYMENT_GATEWAY'] = 'simulator';
$_ENV['PDF_RENDERER'] = 'dompdf';

final class E2eOutbox implements SmsGateway, MailGateway
{
    public function __construct(private readonly string $file)
    {
    }

    public function send(string $to, string $subjectOrMessage, string $body = ''): string
    {
        $entry = $body === ''
            ? ['channel' => 'sms', 'to' => $to, 'message' => $subjectOrMessage]
            : ['channel' => 'mail', 'to' => $to, 'subject' => $subjectOrMessage, 'message' => $body];
        file_put_contents($this->file, json_encode($entry + ['at' => date(DATE_ATOM)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

        return 'e2e-' . bin2hex(random_bytes(6));
    }
}

$items = [];
foreach (glob($root . '/config/*.php') ?: [] as $file) {
    $items[basename($file, '.php')] = require $file;
}
$items = array_replace_recursive($items, [
    'app' => ['env' => 'testing', 'debug' => false, 'url' => 'http://127.0.0.1', 'log_path' => $dir . '/app.log',
        'two_factor_required' => getenv('E2E_2FA') === '1'],
    'database' => ['driver' => 'sqlite', 'database' => $dir . '/scholaris.sqlite'],
    'identity' => ['argon_memory' => 1024, 'argon_time' => 1],
    'people' => ['storage_path' => $dir . '/uploads'],
    'organisation' => ['storage_path' => $dir . '/uploads'],
    'backup' => ['path' => $dir . '/backups', 'key' => '', 'keep' => 1],
    'api' => ['payment_webhook_secret' => 'secret-webhook-e2e'],
]);
// Limites de débit levées : un parcours complet se connecte plusieurs fois par minute depuis la même adresse.
$items['api']['rate_limits'] = [];
@mkdir($dir . '/uploads', 0o777, true);

$outbox = new E2eOutbox($dir . '/outbox.jsonl');

// $e2eOverrides (facultatif, défini par seed.php avant inclusion) : passerelles d'enregistrement des tests réutilisés.
$kernel = Kernel::boot($root, Config::fromArray($items), ($e2eOverrides ?? []) + [SmsGateway::class => $outbox, MailGateway::class => $outbox]);
// Base jetable : journal WAL et écriture sans attente disque (sous Windows, la migration complète passe de ~50 s à ~10 s).
$kernel->container()->get(ConnectionFactory::class)->pdo()->exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = OFF');

return $kernel;
