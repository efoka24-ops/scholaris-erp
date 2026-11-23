<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Policies;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/**
 * Autorisations du module Organisation : lecture (organisation.read) et gestion (organisation.manage).
 * Refus par défaut : sans permission explicite accordée par le module Identity, la réponse est 403.
 */
final class OrganisationPolicy
{
    public const READ = 'organisation.read';
    public const MANAGE = 'organisation.manage';

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    public function canRead(Principal $principal, ?string $establishmentId): bool
    {
        return $this->permissions->can($principal, self::READ, $establishmentId)
            || $this->permissions->can($principal, self::MANAGE, $establishmentId);
    }

    public function canManage(Principal $principal, ?string $establishmentId): bool
    {
        return $this->permissions->can($principal, self::MANAGE, $establishmentId);
    }

    public function authorizeRead(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->canRead($principal, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter l\'organisation.');
        }
    }

    public function authorizeManage(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->canManage($principal, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de modifier l\'organisation.');
        }
    }
}
