<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Envoi de messages à une personne à partir d'un modèle bilingue (SMS, e-mail, notification).
 * Respecte le consentement et les plages horaires (7 h à 21 h, hors urgence). Fourni par le module Messaging (S05).
 */
interface Messenger
{
    /**
     * @param array<string, scalar> $variables variables du modèle (ex. receipt_number, amount)
     * @param list<string> $channels sms, email, push ; vide = canaux préférés du modèle
     */
    public function send(
        string $tenantId,
        string $establishmentId,
        string $personId,
        string $templateKey,
        array $variables = [],
        array $channels = [],
        bool $urgent = false,
    ): DeliveryResult;
}
