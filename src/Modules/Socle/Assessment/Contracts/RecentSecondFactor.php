<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Contrat SORTANT local : l'utilisateur a-t-il validé une double authentification récente ? Exigé par les transitions
 * du cycle des notes qui le déclarent (T328). L'implémentation par défaut passe par Identity\Contracts\RecentSecondFactor.
 */
interface RecentSecondFactor
{
    public function isRecent(string $tenantId, string $userId): bool;
}
