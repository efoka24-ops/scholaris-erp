<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/** Passerelle voix du socle : même contrat que les autres canaux, avec repli SMS si désactivée. */
interface VoiceGateway
{
    public function send(string $to, string $message): string;
}