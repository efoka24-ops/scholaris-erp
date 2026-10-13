<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;
use function DI\get;

use Psr\Container\ContainerInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Modules\Socle\Audit\Application\AuditDataSources;
use Scholaris\Modules\Socle\Audit\Application\DataSources;
use Scholaris\Modules\Socle\Audit\Console\DbCheckCommand;
use Scholaris\Modules\Socle\Audit\Contracts\PendingApprovals;
use Scholaris\Modules\Socle\Audit\Infrastructure\PdoPendingApprovals;

/**
 * Liaisons du module Audit. Les sources de données des autres modules sont découvertes par convention : toute clé
 * `privacy.tenant_data_source.*` (TenantDataSource) ou `privacy.subject_data_source.*` (SubjectDataSource) déclarée dans le
 * `definitions.php` d'un module est résolue dans le conteneur et enregistrée dans DataSources.
 */
return [
    PendingApprovals::class => autowire(PdoPendingApprovals::class),

    'privacy.tenant_data_source.audit' => get(AuditDataSources::class),
    'privacy.subject_data_source.audit' => get(AuditDataSources::class),

    DataSources::class => factory(static function (ContainerInterface $container): DataSources {
        $tenant = [];
        $subject = [];
        foreach (glob(dirname(__DIR__, 4) . '/src/Modules/*/*/definitions.php') ?: [] as $file) {
            foreach (array_keys((array) require $file) as $key) {
                if (str_starts_with((string) $key, 'privacy.tenant_data_source.')) {
                    $tenant[] = $container->get((string) $key);
                } elseif (str_starts_with((string) $key, 'privacy.subject_data_source.')) {
                    $subject[] = $container->get((string) $key);
                }
            }
        }

        return new DataSources($tenant, $subject);
    }),

    // Commandes console du module (clé console.command.<nom>).
    'console.command.backup:run' => autowire(\Scholaris\Modules\Socle\Audit\Console\BackupRunCommand::class),
    'console.command.backup:restore' => autowire(\Scholaris\Modules\Socle\Audit\Console\BackupRestoreCommand::class),
    'console.command.backup:keygen' => autowire(\Scholaris\Modules\Socle\Audit\Console\BackupKeygenCommand::class),
    \Scholaris\Modules\Socle\Audit\Console\BackupCommands::class => factory(static fn (\Scholaris\Modules\Socle\Audit\Application\BackupService $service, Config $config): \Scholaris\Modules\Socle\Audit\Console\BackupCommands => new \Scholaris\Modules\Socle\Audit\Console\BackupCommands($service, $config, dirname(__DIR__, 4))),
    \Scholaris\Modules\Socle\Audit\Application\BackupService::class => factory(static fn (ConnectionFactory $connections, \Scholaris\Core\RateLimit\Clock $clock, Config $config): \Scholaris\Modules\Socle\Audit\Application\BackupService => new \Scholaris\Modules\Socle\Audit\Application\BackupService($connections, $clock, (string) $config->get('app.timezone', 'Africa/Douala'))),
    'console.command.db:check' => factory(static fn (ConnectionFactory $connections, Migrator $migrator, Config $config): DbCheckCommand => new DbCheckCommand($connections, $migrator, $config, dirname(__DIR__, 4))),
];
