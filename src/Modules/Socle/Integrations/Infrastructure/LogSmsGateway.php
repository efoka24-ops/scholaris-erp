<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;

/** Simulateur : journalise le SMS (numéro masqué) au lieu de l'envoyer. */
final class LogSmsGateway implements SmsGateway
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(string $to, string $message): string
    {
        $reference = 'sim-sms-' . new Ulid();
        $this->logger->info('SMS simulé', ['to' => self::mask($to), 'length' => mb_strlen($message), 'ref' => $reference]);

        return $reference;
    }

    private static function mask(string $phone): string
    {
        return strlen($phone) > 4 ? str_repeat('•', strlen($phone) - 4) . substr($phone, -4) : '••••';
    }
}
