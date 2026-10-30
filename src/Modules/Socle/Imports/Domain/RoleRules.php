<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Domain;

/**
 * Rôles qu'un fichier peut contenir pour un type d'établissement. Le socle ne connaît pas les applications : la famille d'un rôle se
 * lit à son code (higher_* = Supérieur/IPES, primary_* et pub_* et pn_* = primaire et maternelle, rôles communs partagés). Les rôles
 * de niveau client (super_admin, direction_groupe, pub_cabinet…) et `platform_admin` ne s'importent jamais : ils se créent dans
 * Administration, par un super administrateur.
 */
final class RoleRules
{
    /** Rôles communs au socle, utilisables dans tous les types d'établissement. */
    private const SHARED = ['enseignant', 'apprenant', 'parent', 'caissier', 'comptable'];

    private function __construct()
    {
    }

    public static function isFamilyOf(string $roleCode, string $establishmentType): bool
    {
        if (in_array($roleCode, self::SHARED, true)) {
            return true;
        }
        $higherRole = str_starts_with($roleCode, 'higher_');

        return $establishmentType === 'higher' ? $higherRole : !$higherRole;
    }

    public static function isTemporary(string $roleCode): bool
    {
        return in_array($roleCode, PeopleColumns::TEMPORARY_ROLES, true);
    }
}
