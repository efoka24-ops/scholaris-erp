<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/** Établissements où l'utilisateur exerce un rôle en vigueur (tous ceux du client pour un rôle de niveau client). Fourni par Identity. */
interface AccessibleEstablishments
{
    /** @return list<string> identifiants d'établissements ; vide pour un compte inconnu ou sans rôle */
    public function idsFor(string $tenantId, string $userId): array;
}
