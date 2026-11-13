<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Mail;

use RuntimeException;

/** Échec d'un envoi SMTP. Le message ne contient jamais d'identifiant ni de mot de passe. */
final class SmtpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $smtpCode = 0)
    {
        parent::__construct($message, $smtpCode);
    }
}
