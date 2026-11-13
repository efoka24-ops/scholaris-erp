<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsStatusLookup;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\HttpSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpSmsGateway;

/** Garde-fou : aucune passerelle réseau n'est choisie sans demande explicite ET configuration complète. */
final class MailSmsGatewaySelectionTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function container(array $extra): \DI\Container
    {
        return Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ] + $extra))->container();
    }

    public function testParDefautLesSimulateursSontUtilises(): void
    {
        $container = $this->container([]);

        self::assertInstanceOf(LogMailGateway::class, $container->get(MailGateway::class));
        self::assertInstanceOf(LogSmsGateway::class, $container->get(SmsGateway::class));
    }

    public function testSmtpSeulementSiMailerSmtpEtHoteRenseigne(): void
    {
        foreach ([['mailer' => 'log', 'host' => 'smtp.example.test'], ['mailer' => 'smtp', 'host' => ''], ['mailer' => 'smtp', 'host' => '  ']] as $mail) {
            self::assertInstanceOf(LogMailGateway::class, $this->container(['mail' => $mail])->get(MailGateway::class), json_encode($mail));
        }

        $smtp = $this->container(['mail' => ['mailer' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587, 'from_address' => 'a@example.test']]);
        self::assertInstanceOf(SmtpMailGateway::class, $smtp->get(MailGateway::class));
    }

    public function testHttpSeulementSiPasserelleHttpEtUrlRenseignee(): void
    {
        foreach ([['gateway' => 'log', 'http' => ['url' => 'https://sms.example.test']], ['gateway' => 'http', 'http' => ['url' => '']], ['gateway' => 'http']] as $sms) {
            self::assertInstanceOf(LogSmsGateway::class, $this->container(['sms' => $sms])->get(SmsGateway::class), json_encode($sms));
        }

        $http = $this->container(['sms' => ['gateway' => 'http', 'http' => ['url' => 'https://sms.example.test/send', 'token' => 't']]]);
        self::assertInstanceOf(HttpSmsGateway::class, $http->get(SmsGateway::class));
    }

    public function testTeeeOtpSeulementSiPiloteTeeeOtpEtCleRenseignee(): void
    {
        $base = 'https://teeeotp.example.test';
        foreach ([
            ['driver' => 'log', 'teeeotp' => ['base_url' => $base, 'api_key' => 'sk_test_x']],
            ['driver' => 'teeeotp', 'teeeotp' => ['base_url' => $base, 'api_key' => '']],
            ['driver' => 'teeeotp', 'teeeotp' => ['base_url' => '', 'api_key' => 'sk_test_x']],
            ['driver' => 'teeeotp'],
            ['gateway' => 'log', 'teeeotp' => ['base_url' => $base, 'api_key' => 'sk_test_x']],
        ] as $sms) {
            self::assertInstanceOf(LogSmsGateway::class, $this->container(['sms' => $sms])->get(SmsGateway::class), json_encode($sms));
        }

        $teeeotp = $this->container(['sms' => ['driver' => 'teeeotp', 'teeeotp' => ['base_url' => $base, 'api_key' => 'sk_test_x']]]);
        self::assertInstanceOf(TeeeOtpSmsGateway::class, $teeeotp->get(SmsGateway::class));
        self::assertInstanceOf(TeeeOtpSmsGateway::class, $teeeotp->get(SmsStatusLookup::class), 'Le suivi d\'état s\'appuie sur la même passerelle.');
    }

    public function testPiloteLEmporteSurLAncienneVariable(): void
    {
        $sms = ['driver' => 'teeeotp', 'gateway' => 'http', 'http' => ['url' => 'https://sms.example.test/send'], 'teeeotp' => ['base_url' => 'https://teeeotp.example.test', 'api_key' => 'sk_test_x']];
        self::assertInstanceOf(TeeeOtpSmsGateway::class, $this->container(['sms' => $sms])->get(SmsGateway::class));

        $sms['driver'] = 'http';
        self::assertInstanceOf(HttpSmsGateway::class, $this->container(['sms' => $sms])->get(SmsGateway::class));

        $sms['driver'] = '';
        self::assertInstanceOf(HttpSmsGateway::class, $this->container(['sms' => $sms])->get(SmsGateway::class), 'Sans SMS_DRIVER, SMS_GATEWAY reste respecté.');
    }

    public function testSansSuiviDEtatLesAutresPilotes(): void
    {
        $container = $this->container([]);
        self::assertInstanceOf(LogSmsGateway::class, $container->get(SmsGateway::class));
        self::assertNull($container->get(SmsStatusLookup::class)->referencePrefix());
    }
}
