<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Registry\Policies;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Refus par défaut. Les scopes territoriaux s'ajoutent au tenant et établissement déjà validés par le noyau. */
final class RegistryPolicy
{
    public const READ = 'public_primary.registry.read';
    public const MANAGE = 'public_primary.registry.manage';

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    public function authorizeRead(Principal $principal, string $establishmentId): void
    {
        if (!$this->permissions->can($principal, self::READ, $establishmentId)
            && !$this->permissions->can($principal, self::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter le registre PublicPrimary.');
        }
    }

    public function authorizeManage(Principal $principal, string $establishmentId): void
    {
        if (!$this->permissions->can($principal, self::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de modifier le registre PublicPrimary.');
        }
    }
}