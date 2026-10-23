<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;

/** Simulateur : journalise l'e-mail (sans son contenu) au lieu de l'envoyer. */
final class LogMailGateway implements MailGateway
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(string $to, string $subject, string $body): string
    {
        $reference = 'sim-mail-' . new Ulid();
        $this->logger->info('E-mail simulé', ['subject' => $subject, 'ref' => $reference]);

        return $reference;
    }
}
