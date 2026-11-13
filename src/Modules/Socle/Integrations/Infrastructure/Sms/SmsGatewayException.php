<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sms;

use RuntimeException;

/** Échec d'envoi d'un SMS. Le message ne contient ni jeton, ni numéro, ni texte du SMS. */
final class SmsGatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
