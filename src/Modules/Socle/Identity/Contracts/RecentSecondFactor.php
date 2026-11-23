<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/** Preuve qu'un utilisateur a validé une double authentification récemment. Fourni par Identity. */
interface RecentSecondFactor
{
    /** Vrai si une session non révoquée et non expirée de l'utilisateur porte une 2FA validée il y a au plus $maxAgeSeconds. */
    public function hasRecent(string $tenantId, string $userId, int $maxAgeSeconds): bool;
}
