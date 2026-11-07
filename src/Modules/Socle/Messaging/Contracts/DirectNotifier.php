<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Message transactionnel de la PLATEFORME vers une adresse qui n'est pas (encore) une personne d'un client :
 * demandeur d'accès, contact de facturation d'un client. Seuls les modèles de plateforme (tenant_id = 'platform')
 * sont utilisables ; variables soumises à la même liste blanche que Messenger. Fourni par Messaging.
 */
interface DirectNotifier
{
    /**
     * Envoie le modèle sur chaque canal disponible (email si $email, sms si $phone).
     *
     * @param array<string, scalar> $variables
     */
    public function notify(string $templateKey, string $locale, ?string $email, ?string $phone, array $variables = []): DeliveryResult;
}
