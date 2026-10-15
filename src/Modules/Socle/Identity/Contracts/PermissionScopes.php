<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

use Scholaris\Core\Security\Principal;

/**
 * Périmètres d'une permission (S15). PermissionChecker::can répond « dans l'établissement » ; ce contrat dit OÙ dans
 * l'établissement : il rend, pour chaque affectation de rôle en vigueur maintenant qui s'exerce dans l'établissement et dont le rôle
 * porte la permission, son périmètre. Les applications développent ensuite les unités et structures (Organisation\OrgUnitTree) et
 * filtrent leurs données. Liste vide = aucun droit (cohérent avec can() faux). Refus par défaut : utilisateur inconnu ou inactif,
 * autre client. Un compte de service qui porte la permission reçoit tout l'établissement.
 */
interface PermissionScopes
{
    /** @return list<Scope> sans doublon ; un périmètre `establishment` couvre tous les autres */
    public function scopesFor(Principal $principal, string $permission, string $establishmentId): array;
}
