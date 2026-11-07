<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Canaux de notification choisis par une personne pour les nouveaux messages de sa messagerie interne, EN PLUS de la
 * notification dans l'application (toujours envoyée). Lié par défaut à « application seulement » ; les réglages
 * personnels (profil) peuvent fournir leur implémentation sans toucher au module Messaging.
 */
interface NotificationChannelPreferences
{
    /** @return list<string> canaux supplémentaires parmi « email » et « sms » ; liste vide = application seulement */
    public function extraChannelsFor(string $tenantId, string $personId): array;
}
