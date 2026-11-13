<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Message de SERVICE essentiel à une famille (décision du 12 novembre 2026 : SMS « bulletin disponible ») : il part même si aucun
 * consentement aux communications n'a été recueilli, mais le refus EXPLICITE est toujours respecté (désinscription du canal :
 * motif `opted_out` ; consentement révoqué : motif `consent_revoked`). Hors plafond annuel des SMS standard. Plages horaires,
 * contact et modèle comme Messenger. C'est l'application qui décide, selon son paramètre, si un message est de ce type.
 * Fourni par Messaging.
 */
interface EssentialMessenger
{
    /**
     * @param array<string, scalar> $variables variables du modèle
     * @param list<string> $channels sms, email, push ; vide = canaux du modèle
     */
    public function sendEssential(
        string $tenantId,
        string $establishmentId,
        string $personId,
        string $templateKey,
        array $variables = [],
        array $channels = [],
    ): DeliveryResult;
}
