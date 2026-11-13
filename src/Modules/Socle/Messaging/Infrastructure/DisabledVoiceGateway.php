<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use RuntimeException;
use Scholaris\Modules\Socle\Messaging\Contracts\VoiceGateway;

/** Passerelle voix désactivée par défaut : elle signale l'indisponibilité pour permettre le repli SMS. */
final class DisabledVoiceGateway implements VoiceGateway
{
    public function send(string $to, string $message): string
    {
        throw new RuntimeException('voice_unavailable');
    }
}