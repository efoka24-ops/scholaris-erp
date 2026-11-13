<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/** Personne liée à un compte utilisateur. Fourni par Identity ; les autres modules ne lisent jamais `users`. */
interface UserPersonLink
{
    /** @return string|null identifiant de la personne, null si le compte n'existe pas dans ce client */
    public function personIdForUser(string $tenantId, string $userId): ?string;
}
