<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use Scholaris\Modules\Socle\Messaging\Contracts\NotificationChannelPreferences;

/** Défaut sûr tant qu'aucun réglage personnel n'existe : notification dans l'application seulement (ni e-mail ni SMS). */
final class InAppOnlyPreferences implements NotificationChannelPreferences
{
    public function extraChannelsFor(string $tenantId, string $personId): array
    {
        return [];
    }
}
