<?php

declare(strict_types=1);

use function DI\factory;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsStatusLookup;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpSettings;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\HttpSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\NoSmsStatusLookup;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpClient;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpSmsGateway;

/** Pilote SMS demandé : SMS_DRIVER (sms.driver) l'emporte sur SMS_GATEWAY (sms.gateway). */
$smsDriver = static function (Config $config): string {
    $driver = strtolower(trim((string) $config->get('sms.driver', '')));

    return $driver !== '' ? $driver : strtolower(trim((string) $config->get('sms.gateway', 'log')));
};

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
    TeeeOtpClient::class => factory(static fn (Config $config): TeeeOtpClient => new TeeeOtpClient(
        (string) $config->get('sms.teeeotp.base_url', ''),
        (string) $config->get('sms.teeeotp.api_key', ''),
        (int) $config->get('sms.teeeotp.timeout_seconds', 10),
    )),
    SmsGateway::class => factory(static function (Config $config, LoggerInterface $logger, ContainerInterface $container) use ($smsDriver): SmsGateway {
        $driver = $smsDriver($config);
        if ($driver === 'teeeotp') {
            if (trim((string) $config->get('sms.teeeotp.api_key', '')) === '' || trim((string) $config->get('sms.teeeotp.base_url', '')) === '') {
                return new LogSmsGateway($logger);
            }

            return new TeeeOtpSmsGateway($container->get(TeeeOtpClient::class), $logger);
        }
        $url = trim((string) $config->get('sms.http.url', ''));
        if ($driver !== 'http' || $url === '') {
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

    // C-112 : état des passerelles pour la santé de la plateforme (pilote, configuration, compte TeeeOTP) et aperçu d'un e-mail.
    \Scholaris\Modules\Socle\Integrations\Contracts\GatewayStatus::class => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Application\ConfiguredGatewayStatus::class),

    // Suivi d'acheminement : seul TeeeOTP sait relire l'état d'un SMS ; les autres pilotes répondent null.
    SmsStatusLookup::class => factory(static fn (SmsGateway $gateway): SmsStatusLookup => $gateway instanceof SmsStatusLookup ? $gateway : new NoSmsStatusLookup()),
    'console.command.sms:check' => factory(static fn (Config $config, TeeeOtpClient $client): \Scholaris\Modules\Socle\Integrations\Console\SmsCheckCommand => new \Scholaris\Modules\Socle\Integrations\Console\SmsCheckCommand(
        $client,
        $smsDriver($config),
        (string) $config->get('sms.teeeotp.api_key', ''),
    )),

    // Exports fichier génériques vers cartescolaire.cm et la tutelle (aucune API spécifiée : voir integrations-externes.md).
    \Scholaris\Modules\Socle\Integrations\Contracts\CartescolaireExporter::class => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Application\CartescolaireFileExporter::class),
    \Scholaris\Modules\Socle\Integrations\Contracts\TutelleExporter::class => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Application\TutelleFileExporter::class),

    // Exports à colonnes configurables par type (S14, CSV/XLSX/JSON) : types déclarés par clé de liste `integrations.export_types.<application>`.
    \Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter::class => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Application\ConfigurableTabularExporter::class),
    'integrations.export_types.socle' => [],

    'console.command.sungku:check' => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Console\SungkuCheckCommand::class),
    'console.command.external:export' => \DI\autowire(\Scholaris\Modules\Socle\Integrations\Console\ExternalExportCommand::class),
];
