<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/** Envoi d'e-mails transactionnels. */
interface MailGateway
{
    /** @return string référence du message */
    public function send(string $to, string $subject, string $body): string;
}
