<?php

declare(strict_types=1);

namespace Scholaris\Core\Console;

use Dotenv\Dotenv;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Application\ReminderService;
use Throwable;

/** Console minimale : une commande = une méthode. */
final class Application
{
    public function __construct(private readonly string $basePath)
    {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        // Le terminal de l'hébergement transmet parfois les guillemets tels quels : on les retire autour de chaque argument.
        $argv = array_map(static fn (string $a): string => (string) preg_replace('/^(["\'])(.*)\1$/s', '$2', $a), $argv);
        $command = $argv[1] ?? 'list';

        try {
            return match ($command) {
                'list' => $this->list(),
                'status' => $this->status(),
                'migrate' => $this->migrate(),
                'migrate:status' => $this->migrateStatus(),
                'audit:verify' => $this->auditVerify($argv[2] ?? null),
                'admin:create' => $this->adminCreate(array_slice($argv, 2)),
                'reminders:send' => $this->remindersSend($argv[2] ?? null),
                'tenant:export' => $this->kernel()->container()->get(\Scholaris\Modules\Socle\Audit\Console\TenantExportCommand::class)->run(array_slice($argv, 2)),
                'retention:run' => $this->kernel()->container()->get(\Scholaris\Modules\Socle\Audit\Console\RetentionRunCommand::class)->run(array_slice($argv, 2)),
                'establishment:create' => $this->kernel()->container()->get(\Scholaris\Modules\Socle\Organisation\Console\CreateEstablishmentCommand::class)->run(array_slice($argv, 2)),
                'attendance:retry-absences' => $this->kernel()->container()->get(\Scholaris\Modules\Socle\Attendance\Console\RetryAbsenceNotificationsCommand::class)->run(array_slice($argv, 2)),
                'queue:send' => $this->kernel()->container()->get(\Scholaris\Modules\Socle\Messaging\Console\SendQueueCommand::class)->run(array_slice($argv, 2)),
                'reports:run' => $this->kernel()->container()->get(\Scholaris\Modules\Socle\Reporting\Console\RunScheduledReportsCommand::class)->run(array_slice($argv, 2)),
                'schedule:run' => $this->scheduleRun(),
                'schedule:list' => $this->scheduleList(),
                'documents:process-jobs' => $this->kernel()->container()->get(\Scholaris\Modules\Socle\Documents\Console\ProcessDocumentJobsCommand::class)->run(array_slice($argv, 2)),
                default => $this->moduleCommand($command, array_slice($argv, 2)),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    private function list(): int
    {
        echo "SCHOLARIS - commandes disponibles\n";
        echo "  list                  liste les commandes\n";
        echo "  status                version de PHP, configuration et état de la base\n";
        echo "  migrate               applique les migrations SQL en attente\n";
        echo "  migrate:status        liste les migrations et leur état\n";
        echo "  audit:verify <client> recalcule la chaîne du journal d'audit d'un client\n";
        echo '  reminders:send [AAAA-MM-JJ]  envoie les relances de tranches (à planifier une fois par jour)
';
        echo "  tenant:export <client> <fichier.zip>  exporte toutes les données d'un client
";
        echo '  retention:run [--apply] [client]  simule (ou applique) la politique de rétention
';
        echo "  attendance:retry-absences [client]  rejoue les notifications d'absence échouées (toutes les heures)\n";
        echo "  queue:send [--limit=N]  reprend les envois en échec (chaque minute)\n";
        echo "  reports:run [AAAA-MM-JJ] [client]  exécute les rapports planifiés échus (une fois par jour)\n";
        echo "  documents:process-jobs [--max-jobs=N] [--chunk=N]  traite les lots de documents en file (chaque minute)\n";
        echo '  establishment:create <client> <nom> <nursery|primary|secondary|higher>  crée un établissement
';
        echo '  db:check              contrôle la base configurée (migrations, schéma, utf8mb4, écriture/lecture annulées)
';
        echo '  sungku:check          contrôle la configuration du mobile money Sungku (sans appel réseau)
';
        echo '  external:export <cartescolaire|tutelle> <client> <établissement> <fichier>  export fichier générique
';
        echo '  backup:run [--keep=N] [--path=dossier]  sauvegarde chiffrée de la base (rétention appliquée, chaque nuit)
';
        echo '  backup:keygen         affiche une nouvelle clé de sauvegarde (à conserver hors du serveur)
';
        echo '  backup:restore <fichier> [--force]  restaure une sauvegarde chiffrée
';
        echo "  admin:create <client> <prénom> <nom> <téléphone|e-mail>  crée le premier super administrateur\n";
        echo "  platform:admin-create <e-mail|téléphone> <prénom> <nom...>  crée un administrateur de plateforme (2FA obligatoire)\n";
        echo "  platform:client-create <application> <type> <e-mail|tel> <prenom> <nom> <etablissement...> [--ville=] [--region=] [--dry-run]  met un client en service\n";
        echo '  schedule:run          exécute les tâches planifiées dues (cron.php, chaque minute)
';
        echo '  schedule:list         liste les tâches planifiées
';
        echo "  platform:billing-run [AAAA-MM-JJ]  relances, impayés et suspensions des abonnements (une fois par jour)\n";

        return 0;
    }

    private function status(): int
    {
        Dotenv::createImmutable($this->basePath)->safeLoad();
        $config = Config::load($this->basePath);

        echo 'PHP          : ' . PHP_VERSION . "\n";
        echo 'Environnement: ' . $config->get('app.env') . "\n";
        echo 'Fuseau       : ' . $config->get('app.timezone') . "\n";

        $missing = $this->missingRequirements((string) $config->get('database.driver', 'mysql'));
        echo $missing === [] ? "Extensions   : ok\n" : 'Extensions   : MANQUANTES : ' . implode(', ', $missing) . "\n";

        $connections = new ConnectionFactory($config);
        if (!$connections->isConfigured()) {
            echo "Base         : non configurée\n";

            return 0;
        }

        try {
            $connections->pdo()->query('SELECT 1');
            echo "Base         : ok\n";

            return 0;
        } catch (Throwable $e) {
            echo 'Base         : erreur (' . $e->getMessage() . ")\n";

            return 1;
        }
    }

    /**
     * Extensions et fonctions PHP indispensables à l'application.
     *
     * @return list<string>
     */
    private function missingRequirements(string $driver): array
    {
        $extensions = ['pdo', 'mbstring', 'json', 'sodium', 'openssl', 'ctype', 'fileinfo', 'curl', 'zip', $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql'];
        $missing = array_values(array_filter($extensions, static fn (string $name): bool => !extension_loaded($name)));
        if (!defined('PASSWORD_ARGON2ID')) {
            $missing[] = 'Argon2id (password_hash)';
        }

        return $missing;
    }

    private function kernel(): Kernel
    {
        return Kernel::boot($this->basePath);
    }

    private function migrate(): int
    {
        $applied = $this->kernel()->container()->get(\Scholaris\Core\Database\Migrator::class)->migrate();
        echo $applied === [] ? "Rien à migrer.\n" : implode("\n", array_map(static fn (string $n): string => 'Appliquée : ' . $n, $applied)) . "\n";

        return 0;
    }

    private function migrateStatus(): int
    {
        foreach ($this->kernel()->container()->get(\Scholaris\Core\Database\Migrator::class)->status() as $name => $applied) {
            echo ($applied ? '[x] ' : '[ ] ') . $name . "\n";
        }

        return 0;
    }

    private function auditVerify(?string $tenantId): int
    {
        if ($tenantId === null || $tenantId === '') {
            fwrite(STDERR, "Usage : artisan audit:verify <identifiant du client>\n");

            return 2;
        }

        $result = $this->kernel()->container()->get(AuditLog::class)->verify($tenantId);
        if ($result->intact) {
            echo "Chaîne intacte : {$result->checked} entrée(s) vérifiée(s).\n";

            return 0;
        }

        echo "RUPTURE DE CHAÎNE à l'entrée n° {$result->brokenAt} ({$result->checked} entrée(s) valides avant).\n";

        return 1;
    }

    /** @param list<string> $args */
    private function adminCreate(array $args): int
    {
        if (count($args) !== 4) {
            fwrite(STDERR, "Usage : artisan admin:create \"<client>\" \"<prénom>\" \"<nom>\" \"<téléphone ou e-mail>\"\n");

            return 2;
        }

        $container = $this->kernel()->container();
        $config = $container->get(Config::class);
        $creator = new CreateAdministrator(
            $container->get(ConnectionFactory::class),
            (string) $config->get('app.timezone', 'Africa/Douala'),
        );
        $result = $creator->create($args[0], $args[1], $args[2], $args[3]);

        echo "Super administrateur créé.\n";
        echo "  Identifiant  : {$result['login']}\n";
        echo "  Mot de passe : {$result['password']}   (affiché une seule fois ; à changer dès la première connexion)\n";
        echo "  Client       : {$result['tenant_id']}\n";
        echo "La double authentification sera exigée à la première connexion.\n";

        return 0;
    }

    private function remindersSend(?string $today): int
    {
        $result = $this->kernel()->container()->get(ReminderService::class)->run($today);
        echo "Relances : {$result['sent']} envoyée(s), {$result['skipped']} ignorée(s) (sans destinataire ou déjà relancées).
";

        return 0;
    }

    /**
     * Exécute les tâches de Schedule dues à la minute courante (heure de l'application). Une tâche en échec n'empêche pas
     * les suivantes ; un verrou évite deux exécutions simultanées. Trace : storage/logs/schedule.log.
     */
    private function scheduleRun(): int
    {
        $lock = fopen($this->basePath . '/storage/schedule.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            echo "Exécution précédente encore en cours : rien à faire.\n";

            return 0;
        }
        $this->kernel(); // fixe le fuseau de l'application (app.timezone)
        $now = new \DateTimeImmutable('now');
        $failed = 0;
        try {
            foreach (Schedule::due($now) as $command) {
                $started = microtime(true);
                ob_start();
                $code = $this->run(['artisan', ...$command]);
                $output = trim((string) ob_get_clean());
                $failed += $code === 0 ? 0 : 1;
                $line = sprintf("[%s] %s : code %d en %.1f s%s\n", $now->format('Y-m-d H:i'), implode(' ', $command), $code, microtime(true) - $started, $code === 0 ? '' : ' | ' . mb_substr(str_replace("\n", ' ', $output), 0, 500));
                @file_put_contents($this->basePath . '/storage/logs/schedule.log', $line, FILE_APPEND);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $failed === 0 ? 0 : 1;
    }

    private function scheduleList(): int
    {
        foreach (Schedule::TASKS as [$expression, $command]) {
            printf("%-18s %s\n", $expression, implode(' ', $command));
        }

        return 0;
    }

    /**
     * Commandes déclarées par les modules : clé de conteneur `console.command.<nom>` (objet avec une méthode `run(array $args): int`).
     *
     * @param list<string> $args
     */
    private function moduleCommand(string $command, array $args): int
    {
        $key = 'console.command.' . $command;
        $container = $this->kernel()->container();
        if (preg_match('/^[a-z][a-z0-9:_-]*$/', $command) === 1 && $container->has($key)) {
            return (int) $container->get($key)->run($args);
        }

        return $this->unknown($command);
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Commande inconnue : {$command}\n");

        return 2;
    }
}
