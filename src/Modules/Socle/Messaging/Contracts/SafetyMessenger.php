<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/** Façade mince pour les messages de sécurité : envoi transactionnel, sans dépendre du consentement aux messages non essentiels. */
interface SafetyMessenger
{
    /** @param array<string, scalar> $variables */
    public function send(string $tenantId, string $personId, string $templateKey, array $variables = []): DeliveryResult;
}