<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Domain;

/** Canaux d'envoi : SMS, e-mail, notification dans l'application (push). WhatsApp viendra en phase 11. */
final class Channel
{
    public const SMS = 'sms';
    public const EMAIL = 'email';
    public const PUSH = 'push';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SMS, self::EMAIL, self::PUSH];
    }
}
