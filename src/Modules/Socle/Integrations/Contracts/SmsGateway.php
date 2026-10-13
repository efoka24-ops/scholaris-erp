<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/** Envoi de SMS (passerelle Jasmin ou fournisseur). Simulateur par défaut. */
interface SmsGateway
{
    /** @return string référence du message chez le fournisseur */
    public function send(string $to, string $message): string;
}
