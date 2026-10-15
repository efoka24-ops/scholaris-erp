<?php

declare(strict_types=1);

use function DI\factory;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpSettings;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\HttpSmsGateway;

/**
 * Sélection des passerelles d'envoi (ces liaisons remplacent celles par défaut de ContainerFactory) :
 * le journal (simulateur) reste le choix par défaut ; le réseau n'est utilisé que sur demande explicite ET configuration complète.
 */
return [
    MailGateway::class => factory(static function (Config $config, LoggerInterface $logger): MailGateway {
        if (!SmtpSettings::isSelected($config)) {
            return new LogMailGateway($logger);
        }

        return new SmtpMailGateway(SmtpSettings::fromConfig($config), $logger);
    }),
    SmsGateway::class => factory(static function (Config $config, LoggerInterface $logger): SmsGateway {
        $url = trim((string) $config->get('sms.http.url', ''));
        if (strtolower((string) $config->get('sms.gateway', 'log')) !== 'http' || $url === '') {
            return new LogSmsGateway($logger);
        }

        return new HttpSmsGateway(
            $url,
            (string) $config->get('sms.http.method', 'POST'),
            array_map('strval', array_filter((array) $config->get('sms.http.headers', []), 'is_scalar')),
            (string) $config->get('sms.http.token', ''),
            (string) $config->get('sms.http.token_header', 'Authorization'),
            (string) $config->get('sms.http.token_prefix', 'Bearer '),
            (array) $config->get('sms.http.body_template', ['to' => '{{to}}', 'message' => '{{message}}']),
            (string) $config->get('sms.http.sender', ''),
            (string) $config->get('sms.http.reference_path', ''),
            (int) $config->get('sms.http.timeout_seconds', 15),
            $logger,
        );
    }),

    // Exports fichier génériques vers cartescolaire.cm et la tutelle (aucune API spécifiée : voir integrations-externes.md).
    \Scholaris\Modules\Socle\Integrations\Contracts\CartescolaireExporter::class => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Application\CartescolaireFileExporter::class),
    \Scholaris\Modules\Socle\Integrations\Contracts\TutelleExporter::class => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Application\TutelleFileExporter::class),

    // Exports à colonnes configurables par type (S14, CSV/XLSX/JSON) : types déclarés par clé de liste `integrations.export_types.<application>`.
    \Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter::class => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Application\ConfigurableTabularExporter::class),
    'integrations.export_types.socle' => [],

    'console.command.sungku:check' => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Console\SungkuCheckCommand::class),
    'console.command.external:export' => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Console\ExternalExportCommand::class),
];
