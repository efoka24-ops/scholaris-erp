<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\HttpSmsGateway;

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
}
