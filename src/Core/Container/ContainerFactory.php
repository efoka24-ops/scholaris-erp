<?php

declare(strict_types=1);

namespace Scholaris\Core\Container;

use function DI\autowire;

use DI\Container;
use DI\ContainerBuilder;

use function DI\create;
use function DI\factory;
use function DI\get;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\I18n\Translator;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\DenyAllEstablishmentAccess;
use Scholaris\Core\Security\DenyAllPermissions;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\NullAuthenticator;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Infrastructure\PdoApprovals;
use Scholaris\Modules\Socle\Audit\Infrastructure\PdoAuditLog;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\SimulatedPaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\SimulatedPdfRenderer;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuClient;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuPaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuWebhook;

/**
 * Conteneur PSR-11 (PHP-DI) : autowiring, plus les liaisons explicites interface -> implémentation.
 * Les modules d'identité remplaceront Authenticator et EstablishmentAccess (refus par défaut ici).
 */
final class ContainerFactory
{
    /** @param array<string, mixed> $overrides définitions qui remplacent les liaisons par défaut (tests, modules) */
    public static function create(string $basePath, Config $config, array $overrides = []): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([
            Config::class => $config,
            Psr17Factory::class => autowire(),
            ResponseFactoryInterface::class => get(Psr17Factory::class),
            StreamFactoryInterface::class => get(Psr17Factory::class),
            ServerRequestFactoryInterface::class => get(Psr17Factory::class),
            UriFactoryInterface::class => get(Psr17Factory::class),
            UploadedFileFactoryInterface::class => get(Psr17Factory::class),

            LoggerInterface::class => factory(static function () use ($basePath, $config): LoggerInterface {
                $path = (string) $config->get('app.log_path', 'storage/logs/app.log');
                if (!str_starts_with($path, '/') && preg_match('#^[A-Za-z]:[\\\\/]#', $path) !== 1) {
                    $path = $basePath . '/' . $path;
                }

                return (new Logger('scholaris'))->pushHandler(new StreamHandler($path));
            }),

            Translator::class => create(Translator::class)->constructor($basePath . '/lang'),
            Migrator::class => create(Migrator::class)->constructor(get(\Scholaris\Core\Database\ConnectionFactory::class), $basePath),

            Authenticator::class => autowire(NullAuthenticator::class),
            EstablishmentAccess::class => autowire(DenyAllEstablishmentAccess::class),
            PermissionChecker::class => autowire(DenyAllPermissions::class),

            AuditLog::class => autowire(PdoAuditLog::class)->constructorParameter('timezone', (string) $config->get('app.timezone', 'Africa/Douala')),
            Approvals::class => autowire(PdoApprovals::class)->constructorParameter('timezone', (string) $config->get('app.timezone', 'Africa/Douala')),

            SmsGateway::class => autowire(LogSmsGateway::class),
            MailGateway::class => autowire(LogMailGateway::class),
            PdfRenderer::class => autowire(SimulatedPdfRenderer::class),
            // Simulateur par défaut (aucun appel réseau). Sungku seulement si PAYMENT_GATEWAY=sungku ET la clé API est renseignée.
            PaymentGateway::class => factory(static function (LoggerInterface $logger) use ($config): PaymentGateway {
                $useSungku = $config->get('sungku.enabled') === true && (string) $config->get('sungku.api_key', '') !== '';
                if (!$useSungku) {
                    return new SimulatedPaymentGateway((string) $config->get('api.payment_webhook_secret', ''));
                }

                return new SungkuPaymentGateway(
                    new SungkuClient((string) $config->get('sungku.base_url'), (string) $config->get('sungku.api_key'), (int) $config->get('sungku.timeout_seconds', 15)),
                    new SungkuWebhook((string) $config->get('sungku.webhook_secret', ''), (int) $config->get('sungku.webhook_tolerance_seconds', 600)),
                    $logger,
                );
            }),
        ]);
        // Chaque module peut déclarer ses liaisons dans src/Modules/<Groupe>/<Module>/definitions.php
        // (tableau de définitions PHP-DI). Elles remplacent les liaisons par défaut ci-dessus.
        $moduleDefinitions = [];
        foreach (glob($basePath . '/src/Modules/*/*/definitions.php') ?: [] as $file) {
            $moduleDefinitions = array_merge($moduleDefinitions, (array) require $file);
        }
        if ($moduleDefinitions !== []) {
            $builder->addDefinitions($moduleDefinitions);
        }
        if ($overrides !== []) {
            $builder->addDefinitions($overrides);
        }

        return $builder->build();
    }
}
