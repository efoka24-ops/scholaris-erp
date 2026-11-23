<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Infrastructure;

use Scholaris\Modules\Socle\Messaging\Contracts\NotificationChannelPreferences;
use Scholaris\Modules\Socle\Platform\Application\UserPreferences;

/** Canaux de notification de la messagerie interne choisis dans « Mon profil » (C-112, raccord de C-113). */
final class PreferenceChannels implements NotificationChannelPreferences
{
    public function __construct(private readonly UserPreferences $preferences)
    {
    }

    public function extraChannelsFor(string $tenantId, string $personId): array
    {
        return $this->preferences->extraChannelsForPerson($tenantId, $personId);
    }
}
