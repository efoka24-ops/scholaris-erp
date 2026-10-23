<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Policies;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Droits territoriaux provisoires, refusés par défaut et subordonnés au tenant/établissement du contexte. */
final class TerritoryPolicy
{
    /** Permission de niveau client : rattacher des établissements à des territoires et gérer l'arbre territorial. */
    public const MANAGE_TERRITORIES = 'organisation.territories.manage';

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    public function authorizeRead(Principal $principal, string $establishmentId): void
    {
        if (!$this->permissions->can($principal, OrganisationPolicy::READ, $establishmentId)
            && !$this->permissions->can($principal, OrganisationPolicy::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter les territoires.');
        }
    }

    /** Gestion de l'arbre : permission client dédiée, ou droit de gestion de l'établissement actif (compatibilité). */
    public function authorizeManage(Principal $principal, string $establishmentId): void
    {
        if (!$this->permissions->can($principal, self::MANAGE_TERRITORIES)
            && !$this->permissions->can($principal, OrganisationPolicy::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de modifier les territoires.');
        }
    }

    /** Rattachement d'établissements : niveau client uniquement, un droit limité à un établissement ne suffit pas. */
    public function authorizeAssign(Principal $principal): void
    {
        if (!$this->permissions->can($principal, self::MANAGE_TERRITORIES)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de rattacher des établissements à un territoire.');
        }
    }
}
